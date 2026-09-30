<?php

declare(strict_types=1);

namespace Dirthara\Migration;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemException;
use Dirthara\Migration\Naming\NamingStrategy;
use Dirthara\Migration\ValueObject\CreatedMigration;
use Dirthara\Migration\Exception\MigrationCreatorException;

use function trim;
use function rtrim;
use function strtr;
use function is_file;
use function var_export;
use function is_readable;
use function file_get_contents;

final readonly class MigrationCreator
{
    private const string TEMPLATE_DIRECTORY = __DIR__ . '/../resources/stubs';

    private const string TABLE_PLACEHOLDER = 'table_name';

    public function __construct(
        private ClockInterface $clock,
        private FilesystemOperator $filesystem,
        private NamingStrategy $namingStrategy,
        private string $templateDirectory = self::TEMPLATE_DIRECTORY,
    ) {}

    /**
     * @throws MigrationCreatorException
     */
    public function create(
        string $directory,
        string $name,
        ?string $table = null,
        ?string $description = null,
        ?string $connection = null,
        MigrationTemplate $template = MigrationTemplate::Basic,
    ): CreatedMigration {
        if (!MigrationName::isValid($name)) {
            throw MigrationCreatorException::invalidName($name);
        }

        $index = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y_m_d_His');
        $path = $this->path($directory, $this->namingStrategy->fileName($name, $index));

        $contents = strtr($this->template($template), [
            '{{ name }}' => $this->export($name),
            '{{ description }}' => $this->export($description),
            '{{ connection }}' => $this->export($connection),
            '{{ table }}' => $this->export($table ?? self::TABLE_PLACEHOLDER),
            '{{ index }}' => $this->export($index),
        ]);

        try {
            if ($this->filesystem->fileExists($path)) {
                throw MigrationCreatorException::migrationAlreadyExists($path);
            }

            $this->filesystem->write($path, $contents);
        } catch (FilesystemException $exception) {
            throw MigrationCreatorException::writeFailed($path, previous: $exception);
        }

        return new CreatedMigration(
            name: $name,
            index: $index,
            description: $description,
            connection: $connection,
            path: $path,
        );
    }

    /**
     * @throws MigrationCreatorException
     */
    private function template(MigrationTemplate $template): string
    {
        $path = rtrim($this->templateDirectory, characters: '/') . '/' . $template->fileName();

        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw MigrationCreatorException::templateUnavailable($template, $path);
        }

        return $contents;
    }

    private function path(string $directory, string $fileName): string
    {
        $directory = trim($directory, characters: '/');

        return $directory === '' ? $fileName : $directory . '/' . $fileName;
    }

    private function export(?string $value): string
    {
        return $value === null ? 'null' : var_export($value, return: true);
    }
}
