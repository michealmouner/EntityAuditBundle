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

namespace SimpleThings\EntityAudit\Exception;

class ConfigurationNotSetException extends \Exception
{
    public function __construct(string $configName)
    {
        parent::__construct($configName);

        $this->message = sprintf('Config "%s" must be set.', $configName);
    }
}
