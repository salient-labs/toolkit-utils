<?php declare(strict_types=1);

namespace Salient\Utility\Event;

use Composer\Autoload\ClassLoader as Loader;
use Composer\InstalledVersions as Installed;

/**
 * Dispatched when package data is received from the Composer runtime API
 *
 * @api
 *
 * @template TData
 */
final class PackageDataReceivedEvent
{
    /** @var TData */
    private $Data;
    /** @var class-string<Installed|Loader> */
    private string $Class;
    private string $Method;
    /** @var mixed[] */
    private array $Arguments;

    /**
     * @param TData $data
     * @param class-string<Installed|Loader> $class
     * @param mixed ...$args
     */
    public function __construct(
        $data,
        string $class,
        string $method,
        ...$args
    ) {
        $this->Data = $data;
        $this->Class = $class;
        $this->Method = $method;
        $this->Arguments = $args;
    }

    /**
     * True if the given Composer runtime API method was called
     *
     * @param class-string<Installed|Loader> $class
     */
    public function isMethod(string $class, string $method): bool
    {
        return strcasecmp($class, $this->Class) === 0
            && strcasecmp($method, $this->Method) === 0;
    }

    /**
     * Get arguments passed to the Composer runtime API when the method was
     * called
     *
     * @return mixed[]
     */
    public function getArguments(): array
    {
        return $this->Arguments;
    }

    /**
     * Get data received from the Composer runtime API
     *
     * @return TData
     */
    public function getData()
    {
        return $this->Data;
    }

    /**
     * Replace data received from the Composer runtime API
     *
     * @param TData $data
     */
    public function setData($data): void
    {
        $this->Data = $data;
    }
}
