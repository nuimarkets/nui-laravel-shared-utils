<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Traits\HasAttachments;

class HasAttachmentsTest extends TestCase
{
    public function test_pivot_relationship_configuration()
    {
        $entity = new TestPivotEntity;
        $relation = $entity->attachments();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertEquals('test_attachments', $relation->getTable());
        $this->assertEquals('entity_id', $relation->getForeignPivotKeyName());
        $this->assertEquals('attachment_id', $relation->getRelatedPivotKeyName());
    }

    public function test_polymorphic_relationship_configuration()
    {
        $entity = new TestPolymorphicEntity;
        $relation = $entity->attachments();

        $this->assertInstanceOf(MorphMany::class, $relation);
        $this->assertEquals('attachable_type', $relation->getMorphType());
    }

    public function test_default_uses_pivot_table_relationship()
    {
        $entity = new TestDefaultEntity;

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($entity);
        $method = $reflection->getMethod('usesPolymorphicAttachments');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($entity));

        $relation = $entity->attachments();
        $this->assertInstanceOf(BelongsToMany::class, $relation);
    }
}

// Test stub classes
class TestPivotEntity extends Model
{
    use HasAttachments;

    protected $table = 'test_entities';

    protected function usesPolymorphicAttachments(): bool
    {
        return false;
    }

    protected function getAttachmentModel(): string
    {
        return TestAttachmentModel::class;
    }

    protected function getAttachmentPivotTable(): string
    {
        return 'test_attachments';
    }

    protected function getAttachmentForeignKey(): string
    {
        return 'entity_id';
    }
}

class TestPolymorphicEntity extends Model
{
    use HasAttachments;

    protected $table = 'test_polymorphic_entities';

    protected function usesPolymorphicAttachments(): bool
    {
        return true;
    }

    protected function getAttachmentModel(): string
    {
        return TestAttachmentModel::class;
    }
}

class TestDefaultEntity extends Model
{
    use HasAttachments;

    protected $table = 'test_default_entities';

    protected function getAttachmentModel(): string
    {
        return TestAttachmentModel::class;
    }

    protected function getAttachmentPivotTable(): string
    {
        return 'test_default_attachments';
    }

    protected function getAttachmentForeignKey(): string
    {
        return 'default_entity_id';
    }
}

class TestAttachmentModel extends Model
{
    protected $table = 'attachments';
}
