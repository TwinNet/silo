<?php

namespace Silo\Inventory\Model;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\ORM\Mapping\Index;

/**
 * @ORM\Table(
 *     name="context_type",
 *     uniqueConstraints={
 *         @UniqueConstraint(name="name_idx", columns={"name"})
 *     }
 * )
 * @ORM\Entity
 */
class ContextType
{
    /**
     * @var int
     *
     * @ORM\Column(name="context_type_id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @ORM\Column(type="string", nullable=false)
     */
    private $name;

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}
