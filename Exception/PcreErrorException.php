<?php declare(strict_types=1);

namespace Salient\Utility\Exception;

/**
 * @api
 */
class PcreErrorException extends AbstractUtilityException
{
    private const ERROR_MESSAGE_MAP = [
        \PREG_NO_ERROR => 'No error',
        \PREG_INTERNAL_ERROR => 'Internal error',
        \PREG_BAD_UTF8_ERROR => 'Malformed UTF-8 characters, possibly incorrectly encoded',
        \PREG_BAD_UTF8_OFFSET_ERROR => 'The offset did not correspond to the beginning of a valid UTF-8 code point',
        \PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exhausted',
        \PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exhausted',
        \PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit exhausted',
    ];

    protected int $PcreError;
    protected string $PcreErrorMessage;
    protected string $Function;
    /** @var array<string,callable(array<string|null>): string>|string[]|string */
    protected $Pattern;
    /** @var string[]|string */
    protected $Subject;
    /** @var array<int,string> */
    private static array $ErrorNameMap;

    /**
     * @param array<string,callable(array<string|null>): string>|string[]|string $pattern
     * @param string[]|string $subject
     */
    public function __construct(?int $error, string $function, $pattern, $subject)
    {
        $error ??= preg_last_error();
        $message = \PHP_VERSION_ID < 80000
            ? self::ERROR_MESSAGE_MAP[$error] ?? 'Unknown error'
            : preg_last_error_msg();

        $this->PcreError = $error;
        $this->PcreErrorMessage = $message;
        $this->Function = $function;
        $this->Pattern = $pattern;
        $this->Subject = $subject;

        parent::__construct(sprintf(
            'Call to %s() failed with %s (%s)',
            $function,
            (self::$ErrorNameMap ??= $this->getErrorNameMap())[$error],
            $message,
        ));
    }

    /**
     * Get the exception's PCRE error code
     */
    public function getPcreError(): int
    {
        return $this->PcreError;
    }

    /**
     * Get the name of the exception's PCRE error
     */
    public function getPcreErrorName(): string
    {
        return self::$ErrorNameMap[$this->PcreError];
    }

    /**
     * Get the exception's PCRE error message
     */
    public function getPcreErrorMessage(): string
    {
        return $this->PcreErrorMessage;
    }

    /**
     * Get the name of the PCRE function that failed
     */
    public function getFunction(): string
    {
        return $this->Function;
    }

    /**
     * Get the pattern passed to the PCRE function
     *
     * @return array<string,callable(array<string|null>): string>|string[]|string
     */
    public function getPattern()
    {
        return $this->Pattern;
    }

    /**
     * Get the subject passed to the PCRE function
     *
     * @return string[]|string
     */
    public function getSubject()
    {
        return $this->Subject;
    }

    /**
     * @return array<int,string>
     */
    private function getErrorNameMap(): array
    {
        /** @var array<string,mixed> */
        $constants = get_defined_constants(true)['pcre'];
        foreach ($constants as $name => $value) {
            if (substr($name, -6) !== '_ERROR') {
                continue;
            }
            /** @var int $value */
            $errors[$value] = $name;
        }

        return $errors ?? [];
    }
}
