<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base validation for attachment uploads.
 * Services can extend and customize as needed.
 */
class AttachmentUploadRequest extends FormRequest
{
    /**
     * Get the validation rules.
     * Override this method to customize validation.
     */
    public function rules(): array
    {
        return [
            'attachments' => 'required|array|min:1|max:10',
            'attachments.*' => $this->getFileValidationRules(),
            'type' => 'nullable|string|in:'.implode(',', $this->getAllowedTypes()),
        ];
    }

    /**
     * Get file validation rules.
     * Override to customize file constraints.
     */
    protected function getFileValidationRules(): array
    {
        return [
            'required',
            'file',
            'max:10240', // 10MB default
            'mimes:'.implode(',', $this->getAllowedMimeTypes()),
        ];
    }

    /**
     * Get allowed MIME types.
     * Override to customize allowed file types.
     */
    protected function getAllowedMimeTypes(): array
    {
        return [
            'jpg', 'jpeg', 'png', 'gif',           // Images
            'pdf',                                  // PDFs
            'doc', 'docx',                          // Word
            'xls', 'xlsx',                          // Excel
            'txt', 'csv',                           // Text
        ];
    }

    /**
     * Get allowed attachment types.
     * Override to customize type categories.
     */
    protected function getAllowedTypes(): array
    {
        return ['image', 'document', 'video', 'text', 'other'];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'attachments.required' => 'At least one attachment is required.',
            'attachments.max' => 'Maximum 10 attachments allowed per request.',
            'attachments.*.required' => 'Each attachment must be a valid file.',
            'attachments.*.file' => 'Each attachment must be a file.',
            'attachments.*.max' => 'Each file must not exceed 10MB.',
            'attachments.*.mimes' => 'Invalid file type. Allowed types: '.
                                     implode(', ', $this->getAllowedMimeTypes()),
        ];
    }
}
