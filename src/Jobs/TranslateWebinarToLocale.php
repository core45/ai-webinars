<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Jobs;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiWebinars\Services\WebinarContentRewriteService;
use Core45\AiWebinars\Services\WebinarPartContentRewriteService;
use Core45\AiWebinars\Services\WebinarPartElementContentRewriteService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class TranslateWebinarToLocale implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 600;

    public readonly string $operationId;

    public function __construct(
        public Model $webinar,
        public string $sourceLocale,
        public string $targetLocale,
        public ?int $userId = null,
        public ?string $lockToken = null,
    ) {
        $this->onQueue('ai-bulk');
        $this->operationId = (string) Str::uuid();
    }

    /**
     * NOTE (08-08 deviation, plan-documented): `$client` is resolved via method
     * injection to satisfy SEAM-08's AiClient seam gate, but is not yet called.
     * This job delegates entirely to the Webinar*ContentRewriteService family's
     * `translateToLocale()`, which is model-bound and has no analog on the flat
     * `AiClient::translate()` contract. Routing the rewrite services' internal
     * calls through `AiClient` is owned by plan 08-15. `$this->operationId` is
     * minted here (once, at dispatch, reused across retries) for 08-15 to
     * thread through.
     */
    public function handle(AiClient $client,
        WebinarContentRewriteService $webinarService,
        WebinarPartContentRewriteService $partService,
        WebinarPartElementContentRewriteService $elementService,
    ): void {
        $lockClass = app(ModelRegistry::class)->getModelClassOrFail('translation_lock');

        /** @phpstan-ignore staticMethod.notFound */
        if ($this->lockToken && $lockClass::isCancelledByToken($this->lockToken)) {
            return;
        }

        Log::info('TranslateWebinarToLocale: Starting', [
            'webinar_id' => $this->webinar->id, // @phpstan-ignore property.notFound
            'source' => $this->sourceLocale,
            'target' => $this->targetLocale,
            'user_id' => $this->userId,
        ]);

        $webinarService->forTranslation();
        $partService->forTranslation();
        $elementService->forTranslation();

        // Thread the job's operationId (minted once at dispatch, stable
        // across retries) so each service derives deterministic per-field
        // UUIDv5 ids — retries replay identical ids and phasea's usage
        // metering does not re-bill already-translated fields (audit 5.4).
        // Part/element services get per-entity ids below (their field keys
        // would otherwise collide across siblings).
        $webinarService->setOperationId($this->operationId);

        if ($this->userId !== null) {
            $webinarService->setUserId($this->userId);
            $partService->setUserId($this->userId);
            $elementService->setUserId($this->userId);
        }

        if ($this->lockToken) {
            /** @phpstan-ignore staticMethod.notFound */
            $webinarService->onCancellation(fn (): bool => $lockClass::isCancelledByToken($this->lockToken));
            /** @phpstan-ignore staticMethod.notFound */
            $partService->onCancellation(fn (): bool => $lockClass::isCancelledByToken($this->lockToken));
            /** @phpstan-ignore staticMethod.notFound */
            $elementService->onCancellation(fn (): bool => $lockClass::isCancelledByToken($this->lockToken));
        }

        $totalProcessed = 0;
        $totalErrors = 0;
        /** @var array<int, string> $allErrors */
        $allErrors = [];

        try {
            // 1. Translate the webinar itself
            $webinarResult = $webinarService->translateToLocale(
                $this->webinar,
                $this->sourceLocale,
                $this->targetLocale,
                overwrite: true,
            );
            $totalProcessed += count($webinarResult['processed_fields']);
            $totalErrors += count($webinarResult['errors']);
            $allErrors = array_merge($allErrors, $webinarResult['errors']);

            // 2. Translate all parts
            $this->webinar->load('parts.elements');
            foreach ($this->webinar->parts as $part) { // @phpstan-ignore property.notFound
                // Deterministic per-part namespace: same across retries,
                // distinct across sibling parts (audit 5.4).
                $partService->setOperationId(Uuid::uuid5($this->operationId, "part:{$part->id}")->toString());

                $partResult = $partService->translateToLocale(
                    $part,
                    $this->sourceLocale,
                    $this->targetLocale,
                    overwrite: true,
                );
                $totalProcessed += count($partResult['processed_fields']);
                $totalErrors += count($partResult['errors']);
                $allErrors = array_merge($allErrors, $partResult['errors']);

                // 3. Translate all elements within each part
                foreach ($part->elements as $element) {
                    // Deterministic per-element namespace (audit 5.4).
                    $elementService->setOperationId(Uuid::uuid5($this->operationId, "element:{$element->id}")->toString());

                    $elementResult = $elementService->translateToLocale(
                        $element,
                        $this->sourceLocale,
                        $this->targetLocale,
                        overwrite: true,
                    );
                    $totalProcessed += count($elementResult['processed_fields']);
                    $totalErrors += count($elementResult['errors']);
                    $allErrors = array_merge($allErrors, $elementResult['errors']);
                }
            }

            Log::info('TranslateWebinarToLocale: Completed', [
                'webinar_id' => $this->webinar->id, // @phpstan-ignore property.notFound
                'total_processed' => $totalProcessed,
                'total_errors' => $totalErrors,
            ]);

            $webinarName = $this->getWebinarName();

            if ($totalErrors > 0 && $totalProcessed === 0) {
                $this->notifyUser(
                    __('Translation failed'),
                    __('Failed to translate webinar ":name" from :source to :target.', [
                        'name' => $webinarName,
                        'source' => $this->sourceLocale,
                        'target' => $this->targetLocale,
                    ]),
                    'danger',
                );

                return;
            }

            if ($totalErrors > 0) {
                $fields = $this->extractFieldNames($allErrors);
                $this->notifyUser(
                    __('Translation partially completed'),
                    __('Webinar ":name" was partially translated to :target. :ok fields OK, :failed failed: :fields', [
                        'name' => $webinarName,
                        'target' => $this->targetLocale,
                        'ok' => $totalProcessed,
                        'failed' => $totalErrors,
                        'fields' => $fields,
                    ]),
                    'warning',
                );

                return;
            }

            if ($this->lockToken) {
                /** @phpstan-ignore staticMethod.notFound */
                $lockClass::releaseByToken($this->lockToken);
            }

            $this->notifyUser(
                __('Translation completed'),
                __('Webinar ":name" has been translated from :source to :target (:processed fields including parts and elements).', [
                    'name' => $webinarName,
                    'source' => $this->sourceLocale,
                    'target' => $this->targetLocale,
                    'processed' => $totalProcessed,
                ]),
                'success',
            );
        } catch (AiRateLimitedException $e) {
            $retryAfter = $e->retryAfter > 0 ? $e->retryAfter : 60;

            Log::warning('TranslateWebinarToLocale: Rate limited, releasing back to queue', [
                'webinar_id' => $this->webinar->id, // @phpstan-ignore property.notFound
                'target_locale' => $this->targetLocale,
                'retry_after_seconds' => $retryAfter,
            ]);

            $this->release($retryAfter);
        } catch (AiBudgetExceededException $e) {
            // Budget exhaustion will not clear within this job's backoff
            // window — retrying burns attempts for nothing. Fail immediately
            // so failed() notifies the user right away (audit §6).
            Log::warning('TranslateWebinarToLocale: AI budget exceeded, failing immediately', [
                'webinar_id' => $this->webinar->id, // @phpstan-ignore property.notFound
                'target_locale' => $this->targetLocale,
            ]);

            $this->fail($e);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->lockToken) {
            $lockClass = app(ModelRegistry::class)->getModelClassOrFail('translation_lock');
            /** @phpstan-ignore staticMethod.notFound */
            $lockClass::restoreSnapshot($this->lockToken);
            /** @phpstan-ignore staticMethod.notFound */
            $lockClass::releaseByToken($this->lockToken);
        }

        Log::error('TranslateWebinarToLocale: Permanently failed', [
            'webinar_id' => $this->webinar->id, // @phpstan-ignore property.notFound
            'source' => $this->sourceLocale,
            'target' => $this->targetLocale,
            'error' => $exception?->getMessage(),
        ]);

        $this->notifyUser(
            __('Translation failed'),
            __('Failed to translate webinar ":name" from :source to :target: :error', [
                'name' => $this->getWebinarName(),
                'source' => $this->sourceLocale,
                'target' => $this->targetLocale,
                'error' => $exception?->getMessage() ?? 'Unknown error',
            ]),
            'danger',
        );
    }

    private function getWebinarName(): string
    {
        if (!$this->webinar instanceof Translatable) {
            /** @phpstan-ignore property.notFound */
            return "#{$this->webinar->id}";
        }

        $translation = $this->webinar->translate($this->sourceLocale);

        /** @phpstan-ignore property.notFound */
        return $translation->name ?? "#{$this->webinar->id}";
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function extractFieldNames(array $errors): string
    {
        return collect($errors)
            ->map(function (string $error): string {
                $parts = explode(' - ', $error, 2);

                return trim($parts[0]);
            })
            ->unique()
            ->implode(', ');
    }

    private function notifyUser(string $title, string $body, string $status): void
    {
        if ($this->userId === null) {
            return;
        }

        $userClass = app(ModelRegistry::class)->getModelClassOrFail('user');
        $user = $userClass::find($this->userId);
        if ($user === null) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        $notification = match ($status) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification->sendToDatabase($user);
    }
}
