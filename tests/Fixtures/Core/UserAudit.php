<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\EntityAuditBundle\Tests\Fixtures\Core;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class UserAudit
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue]
    protected $id;

    #[ORM\OneToOne(targetEntity: ProfileAudit::class, mappedBy: 'user')]
    private ?ProfileAudit $profile = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING)]
        private string $name
    ) {
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getProfile(): ?ProfileAudit
    {
        return $this->profile;
    }

    public function setProfile(ProfileAudit $profile): void
    {
        $this->profile = $profile;
        $profile->setUser($this);
    }
}
