<?php
/**
 * Athlete Result Model - PHP 8.4 Modern Implementation
 *
 * Represents a single race result with Property Hooks and Asymmetric Visibility.
 *
 * @package PAUSATF\Results\Models
 * @since 3.0.0
 * @requires PHP 8.4
 */

declare(strict_types=1);

namespace PAUSATF\Results\Models;

use PAUSATF\Results\Contracts\Arrayable;
use PAUSATF\Results\Contracts\Jsonable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Athlete result entity with computed performance metrics
 */
final class AthleteResult implements Arrayable, Jsonable
{
    /**
     * Result ID - public read only
     */
    public private(set) ?int $id = null;

    /**
     * Event ID - public read only after creation
     */
    public private(set) int $eventId;

    /**
     * Athlete name with trimming
     */
    public string $athleteName {
        get => $this->athleteName;
        set(string $value) {
            $this->athleteName = trim($value);
        }
    } = '';

    /**
     * Athlete post ID (if linked)
     */
    public private(set) ?int $athleteId = null;

    /**
     * Place/position with validation
     */
    public ?int $place {
        get => $this->place;
        set(?int $value) {
            if ($value !== null && $value < 1) {
                throw new \InvalidArgumentException('Place must be at least 1');
            }
            $this->place = $value;
        }
    } = null;

    /**
     * Overall place (may differ from division place)
     */
    public ?int $overallPlace = null;

    /**
     * Division/age group
     */
    public string $division = '';

    /**
     * Division place
     */
    public ?int $divisionPlace = null;

    /**
     * Gender
     */
    public string $gender {
        get => $this->gender;
        set(string $value) {
            $normalized = strtoupper(trim($value));
            if ($normalized !== '' && !in_array($normalized, ['M', 'F', 'X'], true)) {
                throw new \InvalidArgumentException("Invalid gender: {$value}. Use M, F, or X");
            }
            $this->gender = $normalized;
        }
    } = '';

    /**
     * Age at time of event
     */
    public ?int $athleteAge {
        get => $this->athleteAge;
        set(?int $value) {
            if ($value !== null && ($value < 1 || $value > 120)) {
                throw new \InvalidArgumentException("Invalid age: {$value}");
            }
            $this->athleteAge = $value;
        }
    } = null;

    /**
     * Bib number
     */
    public string $bibNumber = '';

    /**
     * Team/club name
     */
    public string $team = '';

    /**
     * City
     */
    public string $city = '';

    /**
     * State
     */
    public string $state = '';

    /**
     * Finish time in seconds with validation
     */
    public ?float $timeSeconds {
        get => $this->timeSeconds;
        set(?float $value) {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('Time cannot be negative');
            }
            $this->timeSeconds = $value;
        }
    } = null;

    /**
     * Formatted time string (virtual computed property)
     */
    public ?string $timeFormatted {
        get {
            if ($this->timeSeconds === null) {
                return null;
            }
            return self::formatTime($this->timeSeconds);
        }
    }

    /**
     * Chip time in seconds
     */
    public ?float $chipTimeSeconds = null;

    /**
     * Chip time formatted (virtual)
     */
    public ?string $chipTimeFormatted {
        get {
            if ($this->chipTimeSeconds === null) {
                return null;
            }
            return self::formatTime($this->chipTimeSeconds);
        }
    }

    /**
     * Pace per mile in seconds (virtual computed)
     */
    public ?float $pacePerMile {
        get {
            if ($this->timeSeconds === null || $this->distanceMiles === null || $this->distanceMiles <= 0) {
                return null;
            }
            return $this->timeSeconds / $this->distanceMiles;
        }
    }

    /**
     * Pace formatted as MM:SS/mile (virtual)
     */
    public ?string $paceFormatted {
        get {
            if ($this->pacePerMile === null) {
                return null;
            }
            $minutes = floor($this->pacePerMile / 60);
            $seconds = $this->pacePerMile % 60;
            return sprintf('%d:%02d/mi', $minutes, $seconds);
        }
    }

    /**
     * Distance in miles (for pace calculation)
     */
    public ?float $distanceMiles = null;

    /**
     * Points earned
     */
    public ?float $points {
        get => $this->points;
        set(?float $value) {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('Points cannot be negative');
            }
            $this->points = $value;
        }
    } = null;

    /**
     * Prize money earned
     */
    public ?float $payout {
        get => $this->payout;
        set(?float $value) {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('Payout cannot be negative');
            }
            $this->payout = $value;
        }
    } = null;

    /**
     * Status (finished, DNF, DNS, DQ)
     */
    public string $status {
        get => $this->status;
        set(string $value) {
            $valid = ['finished', 'dnf', 'dns', 'dq', ''];
            $normalized = strtolower(trim($value));
            if (!in_array($normalized, $valid, true)) {
                throw new \InvalidArgumentException("Invalid status: {$value}");
            }
            $this->status = $normalized ?: 'finished';
        }
    } = 'finished';

    /**
     * Did the athlete finish?
     */
    public bool $isFinisher {
        get => $this->status === 'finished';
    }

    /**
     * Is this a podium finish (top 3)?
     */
    public bool $isPodium {
        get => $this->place !== null && $this->place <= 3;
    }

    /**
     * Is this a winning result?
     */
    public bool $isWinner {
        get => $this->place === 1;
    }

    /**
     * Raw result notes
     */
    public string $notes = '';

    /**
     * Source of this result (timing system, manual, etc.)
     */
    public string $source = '';

    /**
     * Confidence score for parsed results (0.0-1.0)
     */
    public ?float $confidence {
        get => $this->confidence;
        set(?float $value) {
            if ($value !== null && ($value < 0.0 || $value > 1.0)) {
                throw new \InvalidArgumentException('Confidence must be between 0.0 and 1.0');
            }
            $this->confidence = $value;
        }
    } = null;

    /**
     * Created timestamp
     */
    public private(set) \DateTimeImmutable $createdAt;

    /**
     * Constructor
     */
    public function __construct(int $eventId)
    {
        $this->eventId = $eventId;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Create from database row
     */
    public static function fromArray(array $data): self
    {
        $result = new self((int) ($data['event_id'] ?? 0));

        // Map database columns to properties
        $mapping = [
            'id' => 'id',
            'athlete_name' => 'athleteName',
            'athlete_id' => 'athleteId',
            'place' => 'place',
            'overall_place' => 'overallPlace',
            'division' => 'division',
            'division_place' => 'divisionPlace',
            'gender' => 'gender',
            'athlete_age' => 'athleteAge',
            'bib_number' => 'bibNumber',
            'team' => 'team',
            'city' => 'city',
            'state' => 'state',
            'time_seconds' => 'timeSeconds',
            'chip_time_seconds' => 'chipTimeSeconds',
            'distance_miles' => 'distanceMiles',
            'points' => 'points',
            'payout' => 'payout',
            'status' => 'status',
            'notes' => 'notes',
            'source' => 'source',
            'confidence' => 'confidence',
        ];

        $reflect = new \ReflectionClass($result);

        foreach ($mapping as $dbKey => $propKey) {
            if (!isset($data[$dbKey])) {
                continue;
            }

            $value = $data[$dbKey];
            $property = $reflect->getProperty($propKey);
            $type = $property->getType();

            // Type conversion
            if ($value !== null && $type instanceof \ReflectionNamedType) {
                $value = match ($type->getName()) {
                    'int' => (int) $value,
                    'float' => (float) $value,
                    'bool' => (bool) $value,
                    default => $value,
                };
            }

            // Handle private(set) vs regular properties
            if (str_contains((string) $property, 'private(set)')) {
                $property->setValue($result, $value);
            } else {
                $result->$propKey = $value;
            }
        }

        if (isset($data['created_at'])) {
            $reflect->getProperty('createdAt')->setValue(
                $result,
                new \DateTimeImmutable($data['created_at'])
            );
        }

        return $result;
    }

    /**
     * Link to athlete post
     */
    public function linkToAthlete(int $athleteId): void
    {
        $this->athleteId = $athleteId;
    }

    /**
     * Set database ID
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Format time in seconds to HH:MM:SS or MM:SS
     */
    public static function formatTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Parse time string to seconds
     */
    public static function parseTime(string $time): ?float
    {
        $time = trim($time);

        if ($time === '' || $time === 'DNF' || $time === 'DNS') {
            return null;
        }

        // Handle HH:MM:SS.ms
        if (preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.(\d+))?$/', $time, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];
            $ms = isset($matches[4]) ? (float) ('0.' . $matches[4]) : 0;

            return ($hours * 3600) + ($minutes * 60) + $seconds + $ms;
        }

        // Handle MM:SS.ms
        if (preg_match('/^(\d+):(\d{2})(?:\.(\d+))?$/', $time, $matches)) {
            $minutes = (int) $matches[1];
            $seconds = (int) $matches[2];
            $ms = isset($matches[3]) ? (float) ('0.' . $matches[3]) : 0;

            return ($minutes * 60) + $seconds + $ms;
        }

        // Handle seconds only
        if (is_numeric($time)) {
            return (float) $time;
        }

        return null;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'athlete_name' => $this->athleteName,
            'athlete_id' => $this->athleteId,
            'place' => $this->place,
            'overall_place' => $this->overallPlace,
            'division' => $this->division,
            'division_place' => $this->divisionPlace,
            'gender' => $this->gender,
            'athlete_age' => $this->athleteAge,
            'bib_number' => $this->bibNumber,
            'team' => $this->team,
            'city' => $this->city,
            'state' => $this->state,
            'time_seconds' => $this->timeSeconds,
            'time_formatted' => $this->timeFormatted,
            'chip_time_seconds' => $this->chipTimeSeconds,
            'chip_time_formatted' => $this->chipTimeFormatted,
            'pace_per_mile' => $this->pacePerMile,
            'pace_formatted' => $this->paceFormatted,
            'distance_miles' => $this->distanceMiles,
            'points' => $this->points,
            'payout' => $this->payout,
            'status' => $this->status,
            'is_finisher' => $this->isFinisher,
            'is_podium' => $this->isPodium,
            'is_winner' => $this->isWinner,
            'notes' => $this->notes,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get database-ready array (excludes computed properties)
     */
    public function toDatabaseArray(): array
    {
        return array_filter([
            'event_id' => $this->eventId,
            'athlete_name' => $this->athleteName,
            'athlete_id' => $this->athleteId,
            'place' => $this->place,
            'overall_place' => $this->overallPlace,
            'division' => $this->division,
            'division_place' => $this->divisionPlace,
            'gender' => $this->gender,
            'athlete_age' => $this->athleteAge,
            'bib_number' => $this->bibNumber,
            'team' => $this->team,
            'city' => $this->city,
            'state' => $this->state,
            'time_seconds' => $this->timeSeconds,
            'chip_time_seconds' => $this->chipTimeSeconds,
            'distance_miles' => $this->distanceMiles,
            'points' => $this->points,
            'payout' => $this->payout,
            'status' => $this->status,
            'notes' => $this->notes,
            'source' => $this->source,
            'confidence' => $this->confidence,
        ], fn($v) => $v !== null && $v !== '');
    }

    /**
     * Convert to JSON
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * JsonSerializable implementation
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
