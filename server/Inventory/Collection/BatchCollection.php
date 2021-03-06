<?php

namespace Silo\Inventory\Collection;

use Doctrine\Common\Collections\Collection;
use Silo\Inventory\Model\MarshallableInterface;
use Silo\Inventory\Model\Batch;
use Silo\Inventory\Model\Product;

/**
 * Advanced operations on Batches ArrayCollection.
 */
class BatchCollection extends \Doctrine\Common\Collections\ArrayCollection implements MarshallableInterface
{
    /**
     * Create a new BatchCollection out of a Collection.
     *
     * @param Collection $collection
     *
     * @return static
     */
    public static function fromCollection(Collection $collection)
    {
        return new static($collection->toArray());
    }

    /**
     * Return a BatchCollection with a copy of each Batch in $this.
     *
     * @return static
     */
    public function copy()
    {
        return new static(array_map(function (Batch $batch) {
            return $batch->copy();
        }, $this->toArray()));
    }

    /**
     * @return static
     */
    public function deduplicateProducts()
    {
        /** @var Batch[] $productMap */
        $productMap = [];
        foreach ($this->copy()->toArray() as $batch) {
            $id = $batch->getProduct()->getId();
            if (isset($productMap[$id])) {
                $productMap[$id]->add($batch->getQuantity());
            } else {
                $productMap[$id] = $batch;
            }
        }

        return new static(array_values($productMap));
    }

    /**
     * Return a BatchCollection with a copy of each Batch in $this.
     *
     * @return static
     */
    public function extract(Product $product)
    {
        return new static(array_filter($this->toArray(), function (Batch $batch) use ($product) {
            return $batch->getProduct()->getSku() === $product->getSku();
        }));
    }

    /**
     * Return a BatchCollection with a opposite copy of each Batch in $this.
     *
     * @return static
     */
    public function opposite()
    {
        return new static(array_map(function (Batch $batch) {
            return $batch->opposite();
        }, $this->toArray()));
    }

    /**
     * {@inheritdoc}
     *
     * Specific to BatchCollection, contains a Batch with the same content
     */
    public function contains($element)
    {
        if (!$element instanceof Batch) {
            throw new \InvalidArgumentException('$element should be of type Batch');
        }
        foreach ($this->toArray() as $batch) {
            if (Batch::compare($batch, $element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge a Batches collection into this one, keeping only one single Batch per different Product.
     *
     * @param Collection $batches
     * @param bool       $add
     *
     * @return $this
     */
    public function merge(Collection $batches, $allowZero = false)
    {
        $that = $this;
        $ref = $batches->toArray();
        array_walk($ref, function (Batch $add) use ($that, $allowZero) {
            $this->addProduct($add->getProduct(), $add->getQuantity(), $allowZero);
        });

        return $this;
    }

    public function addBatch(Batch $batch)
    {
        $this->addProduct($batch->getProduct(), $batch->getQuantity());
    }

    /**
     * Add a single quantity of product to the current BatchCollection.
     *
     * @param Product $product
     * @param $quantity
     * @todo allowZero should be a constructing option of BatchCollection
     */
    public function addProduct(Product $product, $quantity, $allowZero = false)
    {
        $founds = $this->filter(function (Batch $batch) use ($product) {
            return $product->getSku() == $batch->getProduct()->getSku();
        });
        if ($founds->count() == 1) {
            $found = $founds->first();
            // @todo what to do ?
            //if ($found->getQuantity() + $quantity === 0) {
            //    $this->removeElement($found);
            //} else {
            $found->add($quantity);
            //}
        } elseif ($founds->count() > 1) {
            throw new \LogicException('You cannot have many Batch with the same Product');
        } else {
            if ($quantity === 0 && !$allowZero) {
                return;
            }
            $this->add(new Batch($product, $quantity));
        }
    }

    /**
     * @return Collection|static
     */
    public function filterZero()
    {
        return $this->filter(function (Batch $batch) {
            return $batch->getQuantity() !== 0;
        });
    }

    /**
     * Compute difference between two BatchCollections by comparing Batches one to one.
     *
     * @param self $from
     *
     * @return self $this - $from
     */
    public function diff(self $from)
    {
        return $this->copy()->merge($from->opposite());
    }

    public function isSameAs(self $a)
    {
        return $this->opposite()->merge($a)->isEmpty();
    }

    public function isSingleBatch()
    {
        return count($this->getValues()) === 1;
    }

    public function hasNegative()
    {
        foreach ($this->getValues() as $batch) { /** @var Batch $batch */
            if ($batch->getQuantity() < 0) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty()
    {
        // Trivial case but...
        if (parent::isEmpty()) {
            return true;
        }

        // ... we can also have Batch with quantity = 0
        foreach ($this->getValues() as $batch) { /** @var Batch $batch */
            if (!$batch->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function getQuantity()
    {
        $sum = function ($a, $b) {
            return $a+$b;
        };
        return array_reduce(array_map(function (Batch $batch) {
            return $batch->getQuantity();
        }, $this->toArray()), $sum, 0);
    }

    /**
     * {@inheritDoc}
     */
    public function toArray()
    {
        return array_values(parent::toArray());
    }

    /**
     * @deprecated use marshall() instead
     */
    public function toRawArray()
    {
        return array_map(
            function (Batch $batch) {
                return [
                    'product' => $batch->getProduct()->getSku(),
                    'name' => $batch->getProduct()->getName(),
                    'quantity' => $batch->getQuantity()
                ];
            },
            $this->toArray()
        );
    }

    public function marshall()
    {
        return $this->toRawArray();
    }


    public function __toString()
    {
        $batches = array_map(function (Batch $b) {
            return (string)$b;
        }, $this->toArray());

        return sprintf(
            "BatchCollection[%s]",
                implode(',', $batches)
            );
    }

    /**
     * @return ArrayCollection
     */
    public function getProducts()
    {
        $products = new ArrayCollection();
        $this->map(function (Batch $batch) use ($products) {
            $products->addUnique($batch->getProduct());
        });

        return $products;
    }

    /**
     * Return a subset of $this containing only the products present in $batches
     * @param BatchCollection $batches
     * @return self
     */
    public function intersectWith(self $batches)
    {
        $products = $batches->getProducts();
        return $this->filter(function (Batch $batch) use ($products) {
            return $products->contains($batch->getProduct());
        });
    }
}
