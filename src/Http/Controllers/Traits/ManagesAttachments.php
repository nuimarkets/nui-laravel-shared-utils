<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Helper methods for attachment CRUD operations in controllers.
 * Includes tenant isolation and authorization checks.
 */
trait ManagesAttachments
{
    /**
     * Verify attachment belongs to entity and tenant.
     *
     * @throws NotFoundHttpException
     * @throws AuthorizationException
     */
    protected function authorizeAttachmentAccess($entity, $attachment): void
    {
        // Verify attachment belongs to entity
        if (! $entity->attachments->contains('id', $attachment->id)) {
            throw new NotFoundHttpException('Attachment not found');
        }

        // Verify tenant isolation (if tenant context exists)
        if (auth()->check() && property_exists($entity, 'tenant_uuid')) {
            $userTenantId = auth()->user()->tenant_uuid ?? auth()->user()->tenant_id ?? null;
            $entityTenantId = $entity->tenant_uuid ?? $entity->tenant_id ?? null;

            if ($userTenantId && $entityTenantId && $userTenantId !== $entityTenantId) {
                throw new AuthorizationException('Unauthorized access to attachment');
            }
        }
    }

    /**
     * List attachments for an entity.
     */
    protected function listAttachmentsResponse($entity): JsonResponse
    {
        $attachments = $entity->attachments->map(function ($attachment) {
            return [
                'uuid' => $attachment->uuid,
                'file_name' => $attachment->file_name,
                'file_size' => $attachment->file_size,
                'type' => $attachment->type,
                'path' => $attachment->bucket_path,
                'created_at' => $attachment->created_at?->toIso8601String(),
            ];
        });

        return new JsonResponse([
            'data' => $attachments,
            'meta' => ['code' => 200],
        ]);
    }

    /**
     * Stream download response for attachment with authorization.
     */
    protected function downloadAttachmentResponse(
        $attachment,
        string $diskName,
        $entity = null
    ): StreamedResponse {
        // Verify authorization if entity provided
        if ($entity) {
            $this->authorizeAttachmentAccess($entity, $attachment);
        }

        if (! $attachment->bucket_path) {
            throw new NotFoundHttpException('Attachment file path not found');
        }

        if (! Storage::disk($diskName)->exists($attachment->bucket_path)) {
            throw new NotFoundHttpException('Attachment file not found in storage');
        }

        return Storage::disk($diskName)->response(
            $attachment->bucket_path,
            $attachment->file_name
        );
    }

    /**
     * Upload success response.
     */
    protected function uploadSuccessResponse(array $attachments, int $count): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'message' => sprintf('%d attachment(s) uploaded successfully.', $count),
                'attachments' => array_map(function ($attachment) {
                    return [
                        'uuid' => $attachment->uuid,
                        'file_name' => $attachment->file_name,
                        'file_size' => $attachment->file_size,
                        'type' => $attachment->type,
                        'path' => $attachment->bucket_path,
                    ];
                }, $attachments),
            ],
            'meta' => ['code' => 201],
        ], 201);
    }

    /**
     * Delete success response.
     */
    protected function deleteAttachmentResponse(): JsonResponse
    {
        return new JsonResponse([
            'meta' => [
                'message' => 'Attachment deleted successfully.',
                'code' => 200,
            ],
        ]);
    }

    /**
     * Error response for attachment operations.
     */
    protected function attachmentErrorResponse(string $message, int $code = 400): JsonResponse
    {
        return new JsonResponse([
            'meta' => [
                'message' => $message,
                'code' => $code,
            ],
        ], $code);
    }
}
