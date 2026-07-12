<?php
/**
 * Sanction Entity Model - PHP 8.4 Modern Implementation
 *
 * Uses PHP 8.4 Property Hooks for validation and computed properties.
 * Uses Asymmetric Visibility for read-only public access with private mutation.
 *
 * @package PAUSATF\Results\Sanctions
 * @since 3.0.0
 * @requires PHP 8.4
 */

declare(strict_types=1);

namespace PAUSATF\Results\Sanctions;

use PAUSATF\Results\Contracts\Arrayable;
use PAUSATF\Results\Contracts\Jsonable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanction entity with PHP 8.4 property hooks and asymmetric visibility
 */
final class Sanction implements Arrayable, Jsonable
{
    /**
     * Valid event types for sanctions
     */
    private const array VALID_EVENT_TYPES = ['road', 'track', 'xc', 'trail', 'racewalk', 'multi'];

    /**
     * Valid local status values
     */
    private const array VALID_STATUSES = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'cancelled'];

    /**
     * Valid national status values
     */
    private const array VALID_NATIONAL_STATUSES = ['not_submitted', 'pending', 'approved', 'denied'];

    /**
     * Sanction ID - public read, private write (asymmetric visibility)
     */
    public private(set) ?int $id = null;

    /**
     * USATF sanction number with validation hook
     */
    public ?string $usatfSanctionNumber {
        get => $this->usatfSanctionNumber;
        set(?string $value) {
            // Validate USATF sanction number format (e.g., PA24-001)
            if ($value !== null && !preg_match('/^[A-Z]{2}\d{2}-\d{3,}$/', $value)) {
                throw new \InvalidArgumentException(
                    "Invalid USATF sanction number format. Expected format: PA24-001"
                );
            }
            $this->usatfSanctionNumber = $value;
        }
    }

    /**
     * National status with validation
     */
    public string $nationalStatus {
        get => $this->nationalStatus;
        set(string $value) {
            if (!in_array($value, self::VALID_NATIONAL_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    "Invalid national status: {$value}. Valid: " . implode(', ', self::VALID_NATIONAL_STATUSES)
                );
            }
            $this->nationalStatus = $value;
        }
    } = 'not_submitted';

    /**
     * Event name - required, trimmed on set
     */
    public string $eventName {
        get => $this->eventName;
        set(string $value) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new \InvalidArgumentException('Event name cannot be empty');
            }
            if (strlen($trimmed) > 255) {
                throw new \InvalidArgumentException('Event name cannot exceed 255 characters');
            }
            $this->eventName = $trimmed;
        }
    } = '';

    /**
     * Event date with validation
     */
    public \DateTimeImmutable $eventDate {
        get => $this->eventDate;
        set(\DateTimeImmutable|string $value) {
            if (is_string($value)) {
                $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                if ($parsed === false) {
                    throw new \InvalidArgumentException("Invalid date format: {$value}. Expected Y-m-d");
                }
                $value = $parsed->setTime(0, 0);
            }
            $this->eventDate = $value;
        }
    }

    /**
     * Optional event end date
     */
    public ?\DateTimeImmutable $eventEndDate {
        get => $this->eventEndDate;
        set(\DateTimeImmutable|string|null $value) {
            if ($value === null) {
                $this->eventEndDate = null;
                return;
            }
            if (is_string($value)) {
                $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                if ($parsed === false) {
                    throw new \InvalidArgumentException("Invalid end date format: {$value}");
                }
                $value = $parsed->setTime(0, 0);
            }
            // End date must be >= start date
            if (isset($this->eventDate) && $value < $this->eventDate) {
                throw new \InvalidArgumentException('End date cannot be before start date');
            }
            $this->eventEndDate = $value;
        }
    } = null;

    /**
     * Event type with validation
     */
    public string $eventType {
        get => $this->eventType;
        set(string $value) {
            if (!in_array($value, self::VALID_EVENT_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Invalid event type: {$value}. Valid: " . implode(', ', self::VALID_EVENT_TYPES)
                );
            }
            $this->eventType = $value;
        }
    } = 'road';

    /**
     * Event distance (e.g., "5K", "Half Marathon")
     */
    public string $eventDistance = '';

    /**
     * Event location details
     */
    public string $eventLocation = '';
    public string $eventCity = '';
    public string $eventState = 'PA';
    public string $eventZip = '';
    public string $eventVenue = '';

    /**
     * Event website URL with validation
     */
    public string $eventWebsite {
        get => $this->eventWebsite;
        set(string $value) {
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Invalid URL: {$value}");
            }
            $this->eventWebsite = $value;
        }
    } = '';

    /**
     * Course certification
     */
    public bool $courseCertified = false;
    public string $courseCertificationNumber = '';

    /**
     * Organizer information
     */
    public string $organizerName = '';

    /**
     * Organizer email with validation
     */
    public string $organizerEmail {
        get => $this->organizerEmail;
        set(string $value) {
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Invalid email: {$value}");
            }
            $this->organizerEmail = $value;
        }
    } = '';

    public string $organizerPhone = '';
    public string $organizerUsatfNumber = '';
    public string $organizationName = '';
    public string $organizationType = '';
    public bool $safesportCompleted = false;
    public ?\DateTimeImmutable $safesportCompletionDate = null;

    /**
     * Participation estimates with auto fee calculation
     */
    public int $estimatedFinishers {
        get => $this->estimatedFinishers;
        set(int $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Estimated finishers cannot be negative');
            }
            $this->estimatedFinishers = $value;
            // Trigger fee recalculation
            $this->recalculateFees();
        }
    } = 0;

    public int $estimatedVolunteers = 0;
    public bool $hasEliteAthletes = false;

    /**
     * Prize money with validation
     */
    public float $prizeMoneyTotal {
        get => $this->prizeMoneyTotal;
        set(float $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Prize money cannot be negative');
            }
            $this->prizeMoneyTotal = $value;
        }
    } = 0.0;

    public bool $hasWheelchairDivision = false;

    /**
     * Event descriptions
     */
    public string $eventDescription = '';
    public string $safetyPlan = '';
    public string $medicalSupport = '';
    public string $courseDescription = '';

    /**
     * Fees - public read, private(set) for controlled mutation
     */
    public private(set) float $nationalFee = 0.0;
    public private(set) float $localFee = 0.0;

    /**
     * Computed total fee (virtual property with hook)
     */
    public float $totalFee {
        get => $this->nationalFee + $this->localFee + ($this->isLateSubmission() ? SanctionFees::LATE_FEE : 0);
    }

    public bool $feePaid = false;
    public ?\DateTimeImmutable $paymentDate = null;
    public string $paymentMethod = '';
    public string $paymentReference = '';

    /**
     * Local workflow status with validation and state machine rules
     */
    public string $localStatus {
        get => $this->localStatus;
        set(string $value) {
            if (!in_array($value, self::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    "Invalid status: {$value}. Valid: " . implode(', ', self::VALID_STATUSES)
                );
            }
            // Enforce state machine transitions
            if (isset($this->localStatus)) {
                $this->validateStatusTransition($this->localStatus, $value);
            }
            $this->localStatus = $value;
        }
    } = 'draft';

    /**
     * Reviewer information - public read, private write
     */
    public private(set) ?int $reviewerId = null;
    public private(set) ?\DateTimeImmutable $reviewedAt = null;
    public string $reviewNotes = '';
    public string $rejectionReason = '';

    /**
     * Timestamps - public read, private write
     */
    public private(set) ?\DateTimeImmutable $submittedAt = null;
    public private(set) ?\DateTimeImmutable $approvedAt = null;
    public private(set) \DateTimeImmutable $createdAt;
    public private(set) \DateTimeImmutable $updatedAt;

    /**
     * User tracking - public read, private write
     */
    public private(set) ?int $applicantUserId = null;
    public private(set) ?int $createdBy = null;

    /**
     * Computed property: is this a multi-day event?
     */
    public bool $isMultiDay {
        get => $this->eventEndDate !== null && $this->eventEndDate > $this->eventDate;
    }

    /**
     * Computed property: event duration in days
     */
    public int $durationDays {
        get {
            if ($this->eventEndDate === null) {
                return 1;
            }
            return (int) $this->eventDate->diff($this->eventEndDate)->days + 1;
        }
    }

    /**
     * Computed property: days until event
     */
    public int $daysUntilEvent {
        get {
            $now = new \DateTimeImmutable('today');
            return (int) $now->diff($this->eventDate)->days * ($this->eventDate >= $now ? 1 : -1);
        }
    }

    /**
     * Computed property: is event in the past?
     */
    public bool $isPastEvent {
        get {
            $compareDate = $this->eventEndDate ?? $this->eventDate;
            return $compareDate < new \DateTimeImmutable('today');
        }
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->eventDate = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Create from database row
     */
    public static function fromArray(array $data): self
    {
        $sanction = new self();

        // Use reflection to set private(set) properties
        $reflect = new \ReflectionClass($sanction);

        foreach ($data as $key => $value) {
            // Convert snake_case to camelCase
            $camelKey = lcfirst(str_replace('_', '', ucwords($key, '_')));

            if (!property_exists($sanction, $camelKey)) {
                continue;
            }

            $property = $reflect->getProperty($camelKey);
            $type = $property->getType();

            // Handle type conversions
            if ($value !== null && $type instanceof \ReflectionNamedType) {
                $value = match ($type->getName()) {
                    'int' => (int) $value,
                    'float' => (float) $value,
                    'bool' => (bool) $value,
                    \DateTimeImmutable::class => is_string($value)
                        ? new \DateTimeImmutable($value)
                        : $value,
                    default => $value,
                };
            }

            // Check if this is a private(set) property
            if (str_contains((string) $property, 'private(set)')) {
                $property->setValue($sanction, $value);
            } else {
                $sanction->$camelKey = $value;
            }
        }

        return $sanction;
    }

    /**
     * Check if submission would be late
     */
    public function isLateSubmission(): bool
    {
        return SanctionFees::is_late_submission($this->eventDate->format('Y-m-d'));
    }

    /**
     * Recalculate fees based on current data
     */
    private function recalculateFees(): void
    {
        $fees = SanctionFees::calculate(
            $this->estimatedFinishers,
            $this->hasEliteAthletes && $this->prizeMoneyTotal > 500,
            false // Late fee is computed separately
        );

        $this->nationalFee = (float) $fees['national'];
        $this->localFee = (float) $fees['local'];
    }

    /**
     * Validate status transitions (state machine)
     */
    private function validateStatusTransition(string $from, string $to): void
    {
        $allowedTransitions = [
            'draft' => ['submitted', 'cancelled'],
            'submitted' => ['under_review', 'rejected', 'cancelled'],
            'under_review' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['cancelled'],
            'rejected' => ['draft'], // Can revise and resubmit
            'cancelled' => [],
        ];

        if (!in_array($to, $allowedTransitions[$from] ?? [], true)) {
            throw new \InvalidArgumentException(
                "Invalid status transition from '{$from}' to '{$to}'"
            );
        }
    }

    /**
     * Submit sanction for review
     */
    public function submit(): void
    {
        $this->localStatus = 'submitted';
        $this->submittedAt = new \DateTimeImmutable();
        $this->touch();
    }

    /**
     * Approve sanction
     */
    public function approve(int $reviewerId, string $notes = '', ?string $usatfNumber = null): void
    {
        $this->localStatus = 'approved';
        $this->reviewerId = $reviewerId;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->approvedAt = new \DateTimeImmutable();
        $this->reviewNotes = $notes;

        if ($usatfNumber !== null) {
            $this->usatfSanctionNumber = $usatfNumber;
            $this->nationalStatus = 'approved';
        }

        $this->touch();
    }

    /**
     * Reject sanction
     */
    public function reject(int $reviewerId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Rejection reason is required');
        }

        $this->localStatus = 'rejected';
        $this->reviewerId = $reviewerId;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->rejectionReason = $reason;
        $this->touch();
    }

    /**
     * Mark as created by user
     */
    public function setCreatedBy(int $userId): void
    {
        $this->createdBy = $userId;
        $this->applicantUserId = $userId;
    }

    /**
     * Set ID after database insert
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Update the updatedAt timestamp
     */
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Check if sanction can be edited
     */
    public function canEdit(): bool
    {
        return in_array($this->localStatus, ['draft', 'rejected'], true);
    }

    /**
     * Check if sanction can be submitted
     */
    public function canSubmit(): bool
    {
        return $this->localStatus === 'draft' && $this->isValid();
    }

    /**
     * Validate required fields
     */
    public function isValid(): bool
    {
        return $this->eventName !== ''
            && $this->organizerName !== ''
            && $this->organizerEmail !== ''
            && $this->eventLocation !== ''
            && $this->eventCity !== '';
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        if ($this->eventName === '') {
            $errors['eventName'] = 'Event name is required';
        }
        if ($this->organizerName === '') {
            $errors['organizerName'] = 'Organizer name is required';
        }
        if ($this->organizerEmail === '') {
            $errors['organizerEmail'] = 'Organizer email is required';
        }
        if ($this->eventLocation === '') {
            $errors['eventLocation'] = 'Event location is required';
        }
        if ($this->eventCity === '') {
            $errors['eventCity'] = 'Event city is required';
        }
        if ($this->daysUntilEvent < 0) {
            $errors['eventDate'] = 'Event date cannot be in the past';
        }

        return $errors;
    }

    /**
     * Convert to array for database storage
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'usatf_sanction_number' => $this->usatfSanctionNumber,
            'national_status' => $this->nationalStatus,
            'event_name' => $this->eventName,
            'event_date' => $this->eventDate->format('Y-m-d'),
            'event_end_date' => $this->eventEndDate?->format('Y-m-d'),
            'event_type' => $this->eventType,
            'event_distance' => $this->eventDistance,
            'event_location' => $this->eventLocation,
            'event_city' => $this->eventCity,
            'event_state' => $this->eventState,
            'event_zip' => $this->eventZip,
            'event_venue' => $this->eventVenue,
            'event_website' => $this->eventWebsite,
            'course_certified' => $this->courseCertified ? 1 : 0,
            'course_certification_number' => $this->courseCertificationNumber,
            'organizer_name' => $this->organizerName,
            'organizer_email' => $this->organizerEmail,
            'organizer_phone' => $this->organizerPhone,
            'organizer_usatf_number' => $this->organizerUsatfNumber,
            'organization_name' => $this->organizationName,
            'organization_type' => $this->organizationType,
            'safesport_completed' => $this->safesportCompleted ? 1 : 0,
            'safesport_completion_date' => $this->safesportCompletionDate?->format('Y-m-d'),
            'estimated_finishers' => $this->estimatedFinishers,
            'estimated_volunteers' => $this->estimatedVolunteers,
            'has_elite_athletes' => $this->hasEliteAthletes ? 1 : 0,
            'prize_money_total' => $this->prizeMoneyTotal,
            'has_wheelchair_division' => $this->hasWheelchairDivision ? 1 : 0,
            'event_description' => $this->eventDescription,
            'safety_plan' => $this->safetyPlan,
            'medical_support' => $this->medicalSupport,
            'course_description' => $this->courseDescription,
            'national_fee' => $this->nationalFee,
            'local_fee' => $this->localFee,
            'total_fee' => $this->totalFee,
            'fee_paid' => $this->feePaid ? 1 : 0,
            'payment_date' => $this->paymentDate?->format('Y-m-d'),
            'payment_method' => $this->paymentMethod,
            'payment_reference' => $this->paymentReference,
            'local_status' => $this->localStatus,
            'reviewer_id' => $this->reviewerId,
            'reviewed_at' => $this->reviewedAt?->format('Y-m-d H:i:s'),
            'review_notes' => $this->reviewNotes,
            'rejection_reason' => $this->rejectionReason,
            'submitted_at' => $this->submittedAt?->format('Y-m-d H:i:s'),
            'approved_at' => $this->approvedAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'applicant_user_id' => $this->applicantUserId,
            'created_by' => $this->createdBy,
        ];
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
