<?php

namespace Silo\Inventory;

use Silex\Application;
use Silex\Api\ControllerProviderInterface;
use Silo\Inventory\Model\Batch;
use Silo\Inventory\Model\BatchCollection;
use Silo\Inventory\Model\Context;
use Silo\Inventory\Model\Location;
use Silo\Inventory\Model\Operation;
use Silo\Inventory\Repository\Modifier;
use Silo\Inventory\Validator\Constraints\LocationExists;
use Silo\Inventory\Validator\Constraints\SkuExists;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Constraint;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Endpoints.
 *
 * @todo should factorize this a bit
 */
class LocationController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        $locations = $app['em']->getRepository('Inventory:Location');
        $locationProvider = function ($code) use ($locations) {
            return $locations->forceFindOneByCode($code);
        };

        /*
         * Inspect a Location given its code
         */
        $controllers->get('/{code}', function ($code, Application $app) {
            /** @var Location $location */
            $location = $app['re']('Inventory:Location')->findOneByCode($code);
            if (!$location || $location->isDeleted()) {
                return new JsonResponse([], Response::HTTP_NOT_FOUND);
            }
            $parent = $location->getParent();

            return new JsonResponse([
                'code' => $code,
                'parent' => $parent ? $parent->getCode() : null,
                'childs' => array_map(function (Location $l) {
                    return $l->getCode();
                }, $location->getChildren()),
            ]);
        });

        /*
         * Create an empty child
         */
        $controllers->post('/{code}/child', function ($code, Application $app, Request $request) {
            $locations = $app['em']->getRepository('Inventory:Location');
            /** @var Location $location */
            $parent = $locations->forceFindOneByCode($code);

            $location = new Location($request->get('name'));
            $app['em']->persist($location);
            $app['em']->flush();

            $operation = new Operation($app['current_user'], null, $parent, $location);
            $app['em']->persist($operation);
            $operation->execute($app['current_user']);
            $app['em']->flush();

            return new JsonResponse(null, Response::HTTP_ACCEPTED);
        });

        /*
         * Move locations to a new parent
         */
        $controllers->patch('/{code}/child', function ($code, Request $request) use ($app) {
            $locations = $app['em']->getRepository('Inventory:Location');
            /** @var Location $location */
            $parent = $locations->forceFindOneByCode($code);

            $operations = [];
            foreach ($request->get('codes') as $childCode) {
                $child = $locations->forceFindOneByCode($childCode);

                $op = new Operation($app['current_user'], $child->getParent(), $parent, $child);
                array_push($operations, $op);

                $app['em']->persist($op);
            }
            $app['em']->flush();

            foreach($operations as $op) {
                $op->execute($app['current_user']);
            }
            $app['em']->flush();

            return new JsonResponse([]);
        });

        /*
         * Inspect content of a Location
         */
        $controllers->get('/{code}/batches', function ($code, Application $app) {
            $query = $app['em']->createQueryBuilder();
            $query->select('location, batch, product')
                ->from('Inventory:Location', 'location')
                ->innerJoin('location.batches', 'batch')
                ->innerJoin('batch.product', 'product')
                ->andWhere('location.code = :code')
                ->andWhere('batch.quantity != 0')
                ->setParameter('code', $code);

            $result = $query->getQuery()->execute();
            if (empty($result)) {
                return new JsonResponse([]);
            }

            return new JsonResponse(
                array_map(function (Batch $b) {
                    return [
                        'product' => $b->getProduct()->getSku(),
                        'quantity' => $b->getQuantity(),
                    ];
                }, $result[0]->getBatches()->toArray())
            );
        });

        $controllers->match('/{location}/batches', function (Location $location, Request $request) use ($app) {
            // Create a BatchCollection out of a CSV file
            $batches = new BatchCollection();

            foreach ($request->request as $line) {
                ++$line;
                /** @var ConstraintViolationList $violations */
                $violations = $app['validator']->validate($line, [
                    new Constraint\Collection([
                        'sku' => [new Constraint\Required(), new SkuExists()],
                        // Negatives ?
                        'quantity' => new Constraint\Range(['min' => -100, 'max' => 100]),
                    ]),
                ]);

                if ($violations->count() > 0) {
                    return new JsonResponse(['errors' => array_map(function ($violation) {
                        return (string) $violation;
                    }, iterator_to_array($violations->getIterator()))], JsonResponse::HTTP_BAD_REQUEST);
                }

                $product = $app['em']->getRepository('Inventory:Product')->findOneBy(['sku' => $line['sku']]);
                $batch = new Batch($product, $line['quantity']);

                $batches->addBatch($batch);
            }

            switch ($request->getMethod()) {
                // Merge the uploaded batch into the location
                case 'PATCH':
                    $typeName = 'batch merge';
                    $operation = new Operation($app['current_user'], null, $location, $batches);
                    break;
                // the Location batches are replaced by the uploaded ones
                // We achieve this by computing the difference and applying it with an operation
                // SOURCE + OP = TARGET
                // hence OP = TARGET - SOURCE
                case 'false':
                    $typeName = 'batch replace';
                    $diffBatches = $batches->diff($location->getBatches());
                    $operation = new Operation($app['current_user'], null, $location, $diffBatches);
                    break;
                default:
                    throw new \Exception('merge parameter should be present and be either true or false');
            }

            $type = $app['em']->getRepository('Inventory:OperationType')->getByName($typeName);
            $operation->setType($type);

            $app['em']->persist($operation);
            $app['em']->flush();
            $operation->execute($app['current_user']);
            $app['em']->flush();

            // Is it merge or adjust ?
            return new JsonResponse(null, JsonResponse::HTTP_ACCEPTED);
        })->method('PATCH')->convert('location', $locationProvider); // PUT ?

        /*
         * Edit Batches in a given Location
         */
        $controllers->post('/{code}/batches', function ($code, Application $app, Request $request) {
            $locations = $app['em']->getRepository('Inventory:Location');
            /** @var Location $location */
            $location = $locations->forceFindOneByCode($code);
            $csv = new \parseCSV();
            $csv->offset = 1;
            $csv->keep_file_data = true;
            $csv->parse($_FILES['file']['tmp_name']);

            // Check first line of file
            $shouldStartWith = $request->get('merge') === 'true' ? 'merge' : 'replace';
            if (substr($csv->file_data, 0, strlen($shouldStartWith)) !== $shouldStartWith) {
                return new JsonResponse(['errors' => ["File should start with \"$shouldStartWith\""]]);
            }

            // Create a BatchCollection out of a CSV file
            $batches = new BatchCollection();
            foreach ($csv->data as $line) {
                ++$line;
                /** @var ConstraintViolationList $violations */
                $violations = $app['validator']->validate($line, [
                    new Constraint\Collection([
                        'sku' => [new Constraint\Required(), new SkuExists()],
                        'quantity' => new Constraint\Range(['min' => -100, 'max' => 100]),
                    ]),
                ]);

                if ($violations->count() > 0) {
                    return new JsonResponse(['errors' => array_map(function ($violation) {
                        return (string) $violation;
                    }, iterator_to_array($violations->getIterator()))]);
                }

                $product = $app['em']->getRepository('Inventory:Product')->findOneBy(['sku' => $line['sku']]);
                $batch = new Batch($product, $line['quantity']);

                $batches->addBatch($batch);
            }

            switch ($request->get('merge')) {
                // Merge the uploaded batch into the location
                case 'true':
                    $typeName = 'batch merge';
                    $operation = new Operation($app['current_user'], null, $location, $batches);
                    break;
                // the Location batches are replaced by the uploaded ones
                // We achieve this by computing the difference and applying it with an operation
                // SOURCE + OP = TARGET
                // hence OP = TARGET - SOURCE
                case 'false':
                    $typeName = 'batch replace';
                    $diffBatches = $batches->diff($location->getBatches());
                    $operation = new Operation($app['current_user'], null, $location, $diffBatches);
                    break;
                default:
                    throw new \Exception('merge parameter should be present and be either true or false');
            }

            $type = $app['em']->getRepository('Inventory:OperationType')->getByName($typeName);
            $operation->setType($type);

            $app['em']->persist($operation);
            $app['em']->flush();
            $operation->execute($app['current_user']);
            $app['em']->flush();

            // Is it merge or adjust ?
            return new Response('', Response::HTTP_ACCEPTED);
        });

        $controllers->get('/{code}/modifiers', function ($code, Application $app, Request $request) {
            $query = $app['em']->createQueryBuilder();
            $query->select('modifier, type, location')
                ->from('Inventory:Modifier', 'modifier')
                ->innerJoin('modifier.location', 'location')
                ->innerJoin('modifier.type', 'type')
                ->andWhere('location.code = :code')
                ->setParameter('code', $code)
            ;

            $result = $query->getQuery()->execute();

            return new JsonResponse(
                array_map(function (\Silo\Inventory\Model\Modifier $modifier) {
                    return [
                        'name' => $modifier->getName(),
                        'value' => $modifier->getValue()
                    ];
                }, $result)
            );
        });

        // does not follow REST
        $controllers->post('/{code}/modifiers', function ($code, Application $app, Request $request) {
            $locations = $app['re']('Inventory:Location');

            $name = $request->get('name');

            /** @var Location $location */
            $location = $locations->forceFindOneByCode($code);
            /** @var Modifier $modifiers */
            $modifiers = $app['re']('Inventory:Modifier');
            $modifiers->add($location, $name);
            $app['em']->flush();

            return new JsonResponse([], Response::HTTP_ACCEPTED);
        });

        // does not follow REST
        $controllers->delete('/{code}/modifiers', function ($code, Application $app, Request $request) {
            $locations = $app['re']('Inventory:Location');

            $name = $request->get('name');

            /** @var Location $location */
            $location = $locations->forceFindOneByCode($code);
            /** @var Modifier $modifiers */
            $modifiers = $app['re']('Inventory:Modifier');
            $modifiers->remove($location, $name);

            $app['em']->flush();

            return new JsonResponse([], Response::HTTP_ACCEPTED);
        });

        $controllers->delete('/{code}', function ($code, Application $app, Request $request) {
            $em = $app['em'];
            $location = $app['re']('Inventory:Location')->forceFindOneByCode($code);
            $operations = $app['re']('Inventory:Operation');

            /*
            if ($location->isDeleted()) {
            throw new \LogicException("$location is already deleted");
            }

            if ($location->getChildren()->count() > 0) {
                throw new \LogicException("Cannot delete $location because it has children");
            }
            */

            // Cancel all pending operations
            $query = $em->createQueryBuilder();
            $query
                ->select('o')
                ->from('Inventory:Operation', 'o')
                ->andWhere($query->expr()->orX(
                    'o.source = :location',
                    'o.target = :location',
                    'o.location = :location'
                ))
                ->andWhere($query->expr()->isNull('o.doneAt'))
                ->andWhere($query->expr()->isNull('o.cancelledAt'))
                ->setParameter('location', $location)
            ;
            foreach ($query->getQuery()->getResult() as $operation) {
                $operation->cancel($app['current_user']);
            }
            $em->flush();

            // Empty and remove location
            // @todo do not empty empty location
            $operations->executeOperation($app['current_user'], $location, null, 'empty location', $location->getBatches());
            $operations->executeOperation($app['current_user'], $location->getParent(), null, 'remove location', $location);

            $em->flush();

            return new JsonResponse([], Response::HTTP_ACCEPTED);
        });

        return $controllers;
    }
}