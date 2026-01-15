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
 *
 * Supports both Laravel auth guards and JWT-based authentication.
 * For JWT services, pass the user object from Request::user().
 */
trait ManagesAttachments
{
    /**
     * Verify attachment belongs to entity and tenant.
     *
     * @param  mixed  $entity  The parent entity
     * @param  mixed  $attachment  The attachment to verify
     * @param  mixed|null  $user  User object (from Request::user() for JWT, or null to use auth())
     *
     * @throws NotFoundHttpException
     * @throws AuthorizationException
     */
    protected function authorizeAttachmentAccess($entity, $attachment, $user = null): void
    {
        // Verify attachment belongs to entity
        if (! $entity->attachments->contains('id', $attachment->id)) {
            throw new NotFoundHttpException('Attachment not found');
        }

        // Get user from parameter or fall back to auth guard
        $user = $user ?? (auth()->check() ? auth()->user() : null);

        // Verify tenant isolation (if tenant context exists)
        if ($user) {
            $userTenantId = data_get($user, 'tenant_uuid') ?? data_get($user, 'tenant_id');
            $entityTenantId = data_get($entity, 'tenant_uuid') ?? data_get($entity, 'tenant_id');

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
     *
     * @param  mixed  $attachment  The attachment to download
     * @param  string  $diskName  S3 disk name
     * @param  mixed|null  $entity  Parent entity for authorization check
     * @param  mixed|null  $user  User object (from Request::user() for JWT, or null to use auth())
     */
    protected function downloadAttachmentResponse(
        $attachment,
        string $diskName,
        $entity = null,
        $user = null
    ): StreamedResponse {
        // Verify authorization if entity provided
        if ($entity) {
            $this->authorizeAttachmentAccess($entity, $attachment, $user);
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
     * Error response for attachment operations (JSON:API format).
     */
    protected function attachmentErrorResponse(string $message, int $code = 400): JsonResponse
    {
        return new JsonResponse([
            'errors' => [
                [
                    'status' => (string) $code,
                    'detail' => $message,
                ],
            ],
        ], $code);
    }
}
