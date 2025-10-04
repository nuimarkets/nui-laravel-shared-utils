<?php

namespace NuiMarkets\LaravelSharedUtils\Traits;

/**
 * Trait for entities that support attachments.
 * Provides standard attachment relationship (pivot or polymorphic).
 */
trait HasAttachments
{
    /**
     * Get all attachments for this entity.
     * Uses polymorphic or pivot relationship based on configuration.
     */
    public function attachments()
    {
        if ($this->usesPolymorphicAttachments()) {
            return $this->morphMany(
                $this->getAttachmentModel(),
                'attachable'
            );
        }

        return $this->belongsToMany(
            $this->getAttachmentModel(),
            $this->getAttachmentPivotTable(),
            $this->getAttachmentForeignKey(),
            'attachment_id'
        )->withTimestamps()->withPivot('added_by');
    }

    /**
     * Determine if using polymorphic or pivot table relationship.
     * Override to return false for pivot table approach.
     */
    protected function usesPolymorphicAttachments(): bool
    {
        return false; // Default to pivot table
    }

    /**
     * Override in child class to specify attachment model.
     */
    protected function getAttachmentModel(): string
    {
        return \App\Entities\Attachment::class;
    }

    /**
     * Override in child class to specify pivot table.
     * Only used when usesPolymorphicAttachments() returns false.
     */
    protected function getAttachmentPivotTable(): string
    {
        return 'entity_attachments';
    }

    /**
     * Override in child class to specify foreign key.
     * Only used when usesPolymorphicAttachments() returns false.
     */
    protected function getAttachmentForeignKey(): string
    {
        return 'entity_id';
    }
}
