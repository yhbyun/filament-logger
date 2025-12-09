<?php

namespace Z3d0X\FilamentLogger\Loggers;

use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\ActivityLogger;
use Spatie\Activitylog\ActivityLogStatus;

abstract class AbstractModelLogger
{
    abstract protected function getLogName(): string;

    protected function getUserName(?Authenticatable $user): string
    {
        if (blank($user) || $user instanceof GenericUser) {
            return 'Anonymous';
        }

        return Filament::getUserName($user);
    }

    protected function getModelName(Model $model)
    {
        return Str::of(class_basename($model))->headline();
    }

    protected function activityLogger(?string $logName = null): ActivityLogger
    {
        $defaultLogName = $this->getLogName();

        $logStatus = app(ActivityLogStatus::class);

        return app(ActivityLogger::class)
            ->useLog($logName ?? $defaultLogName)
            ->setLogStatus($logStatus);
    }

    protected function getLoggableAttributes(Model $model, mixed $values = []): array
    {
        if (! is_array($values)) {
            return [];
        }

        if (count($model->getVisible()) > 0) {
            $values = array_intersect_key($values, array_flip($model->getVisible()));
        }

        if (count($model->getHidden()) > 0) {
            $values = array_diff_key($values, array_flip($model->getHidden()));
        }

        // 타임스탬프 제외 (선택사항)
        $excludeFields = ['created_at', 'updated_at', 'deleted_at'];
        $values = array_diff_key($values, array_flip($excludeFields));

        // old 값 추출
        $original = $model->getOriginal();
        $changed = [];
        $old = [];

        foreach ($values as $key => $newValue) {
            $oldValue = $original[$key] ?? null;

            // 값이 실제로 변경되었을 때만 추가
            if ($oldValue !== $newValue) {
                $changed[$key] = $newValue;
                $old[$key] = $oldValue;
            }
        }

        if (empty($changed)) {
            return [];
        }

        return [
            'attributes' => $changed,
            'old' => $old,
        ];
    }

    protected function log(Model $model, string $event, ?string $description = null, mixed $attributes = null)
    {
        if (is_null($description)) {
            $description = $this->getModelName($model).' '.$event;
        }

        if (auth()->check()) {
            $description .= ' by '.$this->getUserName(auth()->user());
        }

        $this->activityLogger()
            ->event($event)
            ->performedOn($model)
            ->withProperties($this->getLoggableAttributes($model, $attributes))
            ->log($description);
    }

    public function created(Model $model)
    {
        $this->log($model, 'Created', attributes: $model->getAttributes());
    }

    public function updated(Model $model)
    {
        $changes = $model->getChanges();

        // Ignore the changes to remember_token
        if (count($changes) === 1 && array_key_exists('remember_token', $changes)) {
            return;
        }

        $this->log($model, 'Updated', attributes: $changes);
    }

    public function deleted(Model $model)
    {
        $this->log($model, 'Deleted');
    }
}
