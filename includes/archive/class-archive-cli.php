<?php
/**
 * Administrator CLI for reproducible archive handoffs.
 *
 * @package PAUSATF\Results\Archive
 */
declare(strict_types=1);

namespace PAUSATF\Results\Archive;

use InvalidArgumentException;
use Throwable;

final class ArchiveCli {
    /** Install additive tables. Requires --user=<administrator>. */
    public function install(array $args, array $options): void {
        $this->run(static function (ArchiveWorkflow $workflow): array { $workflow->install(); return ['installed' => true]; });
    }

    /**
     * Stage an immutable artifact; no public results are written.
     *
     * ## OPTIONS
     *
     * <bundle>
     * : Path to the JSON context bundle.
     *
     * --source=<file>
     * : Original file whose bytes match the bundle checksum.
     */
    public function stage(array $args, array $options): void {
        $this->run(function (ArchiveWorkflow $workflow) use ($args, $options): array {
            $json = $this->file($args[0] ?? null, ArchiveBundle::MAX_JSON_BYTES);
            $id = $workflow->stage($json, $this->file($options['source'] ?? null, ArchiveBundle::MAX_SOURCE_BYTES), get_current_user_id());
            return ['id' => $id, 'bundle_sha256' => hash('sha256', $json), 'state' => $workflow->inspect($id)['state']];
        });
    }

    /**
     * Submit independent verification of this exact artifact.
     *
     * ## OPTIONS
     *
     * <id>
     * : Candidate identifier.
     *
     * --report=<file>
     * : Verification JSON with source and bundle checksums.
     *
     * --source=<file>
     * : Independently inspected original source file.
     */
    public function review(array $args, array $options): void {
        $this->run(function (ArchiveWorkflow $workflow) use ($args, $options): array {
            $id = ArchiveModule::id($args[0] ?? null);
            $workflow->review($id, $this->file($options['report'] ?? null, ArchiveBundle::MAX_JSON_BYTES), $this->file($options['source'] ?? null, ArchiveBundle::MAX_SOURCE_BYTES), get_current_user_id());
            return ['id' => $id, 'state' => $workflow->inspect($id)['state']];
        });
    }

    /**
     * Publish an independently verified immutable version.
     *
     * ## OPTIONS
     *
     * <id>
     * : Verified candidate identifier.
     */
    public function publish(array $args, array $options): void {
        $this->run(static function (ArchiveWorkflow $workflow) use ($args): array {
            $id = ArchiveModule::id($args[0] ?? null); $workflow->publish($id); return ['id' => $id, 'state' => 'published'];
        });
    }

    /**
     * Withdraw a published version without deleting historical evidence.
     *
     * ## OPTIONS
     *
     * <id>
     * : Published release identifier.
     */
    public function withdraw(array $args, array $options): void {
        $this->run(static function (ArchiveWorkflow $workflow) use ($args): array {
            $id = ArchiveModule::id($args[0] ?? null); $workflow->withdraw($id); return ['id' => $id, 'state' => 'withdrawn'];
        });
    }

    /**
     * Inspect state and the source-bound record identifiers for review.
     *
     * ## OPTIONS
     *
     * <id>
     * : Candidate identifier.
     */
    public function inspect(array $args, array $options): void {
        $this->run(static function (ArchiveWorkflow $workflow) use ($args): array {
            $row = $workflow->inspect(ArchiveModule::id($args[0] ?? null));
            if ($row === null) { throw new InvalidArgumentException('Archive candidate not found.'); }
            $bundle = json_decode($row['bundle_json'], true, 64, JSON_THROW_ON_ERROR);
            $keys = [];
            foreach (ArchiveBundle::ENTITIES as $kind) { foreach (ArchiveBundle::records($bundle, $kind) as $record) { $keys[] = $kind . ':' . $record['key']; } }
            return ['id' => (int) $row['id'], 'state' => $row['state'], 'bundle_sha256' => $row['bundle_sha256'], 'source_sha256' => $bundle['source']['sha256'], 'candidate_records' => $keys];
        });
    }

    private function run(callable $operation): void {
        if (!current_user_can('manage_options')) { \WP_CLI::error('Use --user with an existing WordPress administrator.'); }
        try { \WP_CLI::line(json_encode($operation(ArchiveModule::workflow()), JSON_THROW_ON_ERROR)); }
        catch (Throwable $error) {
            \WP_CLI::error($error instanceof InvalidArgumentException ? $error->getMessage() : 'Archive operation was not confirmed. Inspect state by artifact checksum before retrying.');
        }
    }

    private function file(mixed $path, int $limit): string {
        if (!is_string($path) || !is_file($path) || !is_readable($path)) { throw new InvalidArgumentException('Provide a readable artifact file.'); }
        $data = file_get_contents($path, false, null, 0, $limit + 1);
        if ($data === false || $data === '' || strlen($data) > $limit) { throw new InvalidArgumentException('Artifact is empty or exceeds the size limit.'); }
        return $data;
    }
}
