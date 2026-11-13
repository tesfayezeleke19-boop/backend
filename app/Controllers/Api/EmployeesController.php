<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\EmployeeModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

class EmployeesController extends BaseController
{
    protected $format = 'json';

    private EmployeeModel $employees;
    private BaseConnection $db;

    private const UPLOAD_DIRECTORY = ROOTPATH . 'file/employess/';
    private const UPLOAD_STORAGE_PREFIX = 'file/employess/';
    private const MAX_PHOTO_SIZE = 10 * 1024 * 1024;

    private const ALLOWED_PHOTO_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct()
    {
        $this->employees = new EmployeeModel();
        $this->db = \Config\Database::connect();
    }

    /**
     * GET /api/employees
     */
    public function index(): ResponseInterface
    {
        try {
            $employees = $this->employees
                ->orderBy('created_at', 'DESC')
                ->findAll();

            $employees = $this->attachPhotos($employees);

            return $this->response->setStatusCode(200)->setJSON([
                'message' => 'Employees retrieved successfully.',
                'data'    => $employees,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Employees index error: ' . $e->getMessage());

            return $this->errorResponse(
                500,
                'Unable to retrieve employees.'
            );
        }
    }

    /**
     * GET /api/employees/{id}
     */
    public function show(string $id = ''): ResponseInterface
    {
        try {
            $employee = $this->findEmployee($id);

            if (!$employee) {
                return $this->errorResponse(404, 'Employee not found.');
            }

            $employees = $this->attachPhotos([$employee]);

            return $this->response->setStatusCode(200)->setJSON([
                'message' => 'Employee retrieved successfully.',
                'data'    => $employees[0],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Employee show error: ' . $e->getMessage());

            return $this->errorResponse(
                500,
                'Unable to retrieve employee.'
            );
        }
    }

    /**
     * POST /api/employees
     *
     * Accepts multipart/form-data:
     * full_name
     * machine_employee_no
     * phone
     * position
     * hired_date
     * emergency_contact_name
     * emergency_phone
     * salary
     * photos[] (multiple images)
     */
    public function create(): ResponseInterface
    {
        $movedFiles = [];
        $transactionStarted = false;

        try {
            $input = $this->getRequestInput();

            $validationErrors = $this->validateEmployeeInput(
                $input,
                true
            );

            if (!empty($validationErrors)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'message' => 'Employee validation failed.',
                    'errors'  => $validationErrors,
                ]);
            }

            $machineId = trim(
                (string) $input['machine_employee_no']
            );

            if ($this->machineIdExists($machineId)) {
                return $this->response->setStatusCode(409)->setJSON([
                    'message' => 'This Machine ID is already assigned to another employee.',
                    'errors'  => [
                        'machine_employee_no' => 'Machine ID already exists.',
                    ],
                ]);
            }

            $photos = $this->getUploadedPhotos();

            $photoErrors = $this->validatePhotos($photos);

            if (!empty($photoErrors)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'message' => 'Photo validation failed.',
                    'errors'  => $photoErrors,
                ]);
            }

            $this->ensureUploadDirectory();

            $employeeId = $this->generateUuid();

            $employeeData = $this->employeeFields($input);

            $employeeData['id'] = $employeeId;
            $employeeData['machine_employee_no'] = $machineId;
            $employeeData['is_active'] = true;

            $this->db->transBegin();
            $transactionStarted = true;

            // PostgreSQL generates employee_id.
            if (!$this->employees->insert($employeeData)) {
                throw new \RuntimeException(
                    'Unable to insert employee: ' .
                    json_encode($this->employees->errors())
                );
            }

            $savedAttachments = [];

            foreach ($photos as $photo) {
                $attachment = $this->saveEmployeePhoto(
                    $employeeId,
                    $photo,
                    $movedFiles
                );

                $savedAttachments[] = $attachment;
            }

            // Maintain compatibility with the legacy single-photo field.
            if (!empty($savedAttachments)) {
                if (!$this->employees->update($employeeId, [
                    'document_attachment_id' => $savedAttachments[0]['id'],
                ])) {
                    throw new \RuntimeException(
                        'Unable to update employee document attachment.'
                    );
                }
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException(
                    'Database transaction failed while creating employee.'
                );
            }

            $this->db->transCommit();
            $transactionStarted = false;

            $employee = $this->employees->find($employeeId);

            if (!$employee) {
                throw new \RuntimeException(
                    'Employee was created but could not be retrieved.'
                );
            }

            $employees = $this->attachPhotos([$employee]);

            return $this->response->setStatusCode(201)->setJSON([
                'message' => 'Employee created successfully.',
                'data'    => $employees[0],
            ]);
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->db->transRollback();
            }

            $this->removeMovedFiles($movedFiles);

            log_message(
                'error',
                'Employee creation error: ' . $e->getMessage()
            );

            return $this->errorResponse(
                500,
                'Unable to create employee.',
                ENVIRONMENT === 'development'
                    ? $e->getMessage()
                    : null
            );
        }
    }

    /**
     * PUT /api/employees/{id}
     *
     * Updates employee information.
     * Existing photos are preserved.
     */
    public function update(string $id = ''): ResponseInterface
    {
        try {
            $employee = $this->findEmployee($id);

            if (!$employee) {
                return $this->errorResponse(404, 'Employee not found.');
            }

            $input = $this->getRequestInput();
            $employeeData = $this->employeeFields($input);

            if (empty($employeeData)) {
                return $this->errorResponse(
                    400,
                    'No valid employee fields were provided.'
                );
            }

            $validationErrors = $this->validateEmployeeInput(
                $input,
                false
            );

            if (!empty($validationErrors)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'message' => 'Employee validation failed.',
                    'errors'  => $validationErrors,
                ]);
            }

            if (array_key_exists('machine_employee_no', $employeeData)) {
                $machineId = trim(
                    (string) $employeeData['machine_employee_no']
                );

                if ($machineId === '') {
                    return $this->response->setStatusCode(422)->setJSON([
                        'message' => 'Machine ID is required.',
                        'errors'  => [
                            'machine_employee_no' => 'Machine ID is required.',
                        ],
                    ]);
                }

                if (
                    $this->machineIdExists(
                        $machineId,
                        $employee['id']
                    )
                ) {
                    return $this->response->setStatusCode(409)->setJSON([
                        'message' => 'This Machine ID is already assigned to another employee.',
                        'errors'  => [
                            'machine_employee_no' => 'Machine ID already exists.',
                        ],
                    ]);
                }

                $employeeData['machine_employee_no'] = $machineId;
            }

            $employeeData['updated_at'] = date('Y-m-d H:i:s');

            if (!$this->employees->update($employee['id'], $employeeData)) {
                return $this->errorResponse(
                    500,
                    'Unable to update employee.'
                );
            }

            $updatedEmployee = $this->employees->find($employee['id']);

            $employees = $this->attachPhotos([$updatedEmployee]);

            return $this->response->setStatusCode(200)->setJSON([
                'message' => 'Employee updated successfully.',
                'data'    => $employees[0],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Employee update error: ' . $e->getMessage());

            return $this->errorResponse(
                500,
                'Unable to update employee.'
            );
        }
    }

    /**
     * PUT /api/employees/{id}/status
     */
    public function setStatus(string $id = ''): ResponseInterface
    {
        try {
            $employee = $this->findEmployee($id);

            if (!$employee) {
                return $this->errorResponse(404, 'Employee not found.');
            }

            $input = $this->getRequestInput();

            if (!array_key_exists('is_active', $input)) {
                return $this->errorResponse(
                    422,
                    'The is_active field is required.'
                );
            }

            $isActive = filter_var(
                $input['is_active'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($isActive === null) {
                return $this->errorResponse(
                    422,
                    'Invalid is_active value.'
                );
            }

            if (!$this->employees->update($employee['id'], [
                'is_active'  => $isActive,
                'updated_at' => date('Y-m-d H:i:s'),
            ])) {
                return $this->errorResponse(
                    500,
                    'Unable to update employee status.'
                );
            }

            return $this->response->setStatusCode(200)->setJSON([
                'message' => 'Employee status updated successfully.',
                'data'    => $this->employees->find($employee['id']),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Employee status error: ' . $e->getMessage());

            return $this->errorResponse(
                500,
                'Unable to update employee status.'
            );
        }
    }

    /**
     * GET /api/employees/photos/{attachmentId}
     *
     * Streams the original image bytes from private storage.
     */
    
/**
 * GET /api/employees/photos/{attachmentId}
 *
 * Returns the original image bytes.
 * The frontend fetches this endpoint with Authorization,
 * converts the response to a Blob, and creates an object URL.
 */
public function viewPhoto(string $attachmentId = ''): ResponseInterface
{
    try {
        if ($attachmentId === '') {
            return $this->errorResponse(
                400,
                'Attachment ID is required.'
            );
        }

        // Retrieve the attachment belonging to an employee.
        $attachment = $this->db
            ->table('employee_attachments ea')
            ->select([
                'a.id',
                'a.file_name',
                'a.storage_key',
                'a.mime_type',
                'a.file_size',
            ])
            ->join(
                'attachments a',
                'a.id = ea.attachment_id'
            )
            ->where('a.id', $attachmentId)
            ->get()
            ->getRowArray();

        if (!$attachment) {
            return $this->errorResponse(
                404,
                'Employee photo not found.'
            );
        }

        // Normalize and validate the private storage path.
        $storageKey = str_replace(
            '\\',
            '/',
            (string) $attachment['storage_key']
        );

        if (
            !str_starts_with(
                $storageKey,
                self::UPLOAD_STORAGE_PREFIX
            )
        ) {
            return $this->errorResponse(
                403,
                'Invalid employee photo storage path.'
            );
        }

        $baseDirectory = realpath(self::UPLOAD_DIRECTORY);

        $filePath = realpath(ROOTPATH . $storageKey);

        if (
            $baseDirectory === false ||
            $filePath === false ||
            !str_starts_with(
                $filePath,
                $baseDirectory . DIRECTORY_SEPARATOR
            ) ||
            !is_file($filePath) ||
            !is_readable($filePath)
        ) {
            return $this->errorResponse(
                404,
                'Employee photo file is unavailable.'
            );
        }

        // Detect the actual image type from the file contents.
        $imageInfo = @getimagesize($filePath);

        if (
            $imageInfo === false ||
            empty($imageInfo['mime'])
        ) {
            return $this->errorResponse(
                415,
                'The stored file is not a valid image.'
            );
        }

        $mimeType = strtolower($imageInfo['mime']);

        if (!in_array(
            $mimeType,
            self::ALLOWED_PHOTO_TYPES,
            true
        )) {
            return $this->errorResponse(
                415,
                'Unsupported employee photo format.'
            );
        }

        // Read the original binary image.
        $imageContents = @file_get_contents($filePath);

        if (
            $imageContents === false ||
            $imageContents === ''
        ) {
            return $this->errorResponse(
                500,
                'Unable to read employee photo.'
            );
        }

        $fileSize = strlen($imageContents);

        $safeFileName = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            basename((string) $attachment['file_name'])
        );

        /*
         * Return the raw image response.
         *
         * Do not use setJSON().
         * Do not Base64-encode the image.
         */
        return $this->response
            ->setStatusCode(200)
            ->setContentType($mimeType)
            ->setHeader('Content-Length', (string) $fileSize)
            ->setHeader(
                'Content-Disposition',
                'inline; filename="' . $safeFileName . '"'
            )
            ->setHeader(
                'X-Content-Type-Options',
                'nosniff'
            )
            ->setHeader(
                'Cache-Control',
                'private, no-store, no-cache, must-revalidate'
            )
            ->setHeader('Pragma', 'no-cache')
            ->setBody($imageContents);

    } catch (Throwable $e) {
        log_message(
            'error',
            'Employee photo view error: ' . $e->getMessage()
        );

        return $this->errorResponse(
            500,
            'Unable to display employee photo.'
        );
    }
}


    /**
     * Extract JSON or regular POST fields.
     */
    private function getRequestInput(): array
    {
        $contentType = strtolower(
            $this->request->getHeaderLine('Content-Type')
        );

        if (str_contains($contentType, 'application/json')) {
            return $this->request->getJSON(true) ?? [];
        }

        $post = $this->request->getPost();

        if (!empty($post)) {
            return $post;
        }

        return $this->request->getRawInput() ?? [];
    }

    /**
     * Whitelist employee fields.
     *
     * employee_id and id are intentionally excluded.
     * PostgreSQL generates employee_id.
     * Machine ID is entered manually.
     */
    private function employeeFields(array $input): array
    {
        $allowed = [
            'machine_employee_no',
            'full_name',
            'phone',
            'position',
            'hired_date',
            'emergency_contact_name',
            'emergency_phone',
            'salary',
            'document_attachment_id',
        ];

        $data = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        return $data;
    }

    /**
     * Validate employee information.
     */
    private function validateEmployeeInput(
        array $input,
        bool $creating
    ): array {
        $errors = [];

        $requiredFields = [
            'machine_employee_no'    => 'Machine ID',
            'full_name'              => 'Full name',
            'phone'                  => 'Phone',
            'position'               => 'Position',
            'hired_date'             => 'Hire date',
            'emergency_contact_name' => 'Emergency contact name',
            'emergency_phone'        => 'Emergency phone',
            'salary'                 => 'Salary',
        ];

        foreach ($requiredFields as $field => $label) {
            if (
                $creating &&
                (
                    !isset($input[$field]) ||
                    trim((string) $input[$field]) === ''
                )
            ) {
                $errors[$field] = $label . ' is required.';
            }
        }

        if (isset($input['machine_employee_no'])) {
            $machineId = trim(
                (string) $input['machine_employee_no']
            );

            if ($machineId === '') {
                $errors['machine_employee_no'] =
                    'Machine ID is required.';
            } elseif (strlen($machineId) > 100) {
                $errors['machine_employee_no'] =
                    'Machine ID cannot exceed 100 characters.';
            }
        }

        if (isset($input['full_name'])) {
            $value = trim((string) $input['full_name']);

            if ($value === '') {
                $errors['full_name'] = 'Full name cannot be empty.';
            } elseif (mb_strlen($value) > 200) {
                $errors['full_name'] =
                    'Full name cannot exceed 200 characters.';
            }
        }

        if (isset($input['phone'])) {
            $value = trim((string) $input['phone']);

            if ($value === '') {
                $errors['phone'] = 'Phone cannot be empty.';
            } elseif (mb_strlen($value) > 60) {
                $errors['phone'] =
                    'Phone cannot exceed 60 characters.';
            }
        }

        if (isset($input['position'])) {
            $value = trim((string) $input['position']);

            if ($value === '') {
                $errors['position'] = 'Position cannot be empty.';
            } elseif (mb_strlen($value) > 120) {
                $errors['position'] =
                    'Position cannot exceed 120 characters.';
            }
        }

        if (isset($input['emergency_contact_name'])) {
            $value = trim(
                (string) $input['emergency_contact_name']
            );

            if ($value === '') {
                $errors['emergency_contact_name'] =
                    'Emergency contact name cannot be empty.';
            } elseif (mb_strlen($value) > 200) {
                $errors['emergency_contact_name'] =
                    'Emergency contact name cannot exceed 200 characters.';
            }
        }

        if (isset($input['emergency_phone'])) {
            $value = trim((string) $input['emergency_phone']);

            if ($value === '') {
                $errors['emergency_phone'] =
                    'Emergency phone cannot be empty.';
            } elseif (mb_strlen($value) > 60) {
                $errors['emergency_phone'] =
                    'Emergency phone cannot exceed 60 characters.';
            }
        }

        if (isset($input['hired_date'])) {
            $date = trim((string) $input['hired_date']);

            $parsedDate = \DateTime::createFromFormat(
                '!Y-m-d',
                $date
            );

            if (
                !$parsedDate ||
                $parsedDate->format('Y-m-d') !== $date
            ) {
                $errors['hired_date'] =
                    'Hire date must be a valid date in YYYY-MM-DD format.';
            }
        }

        if (isset($input['salary'])) {
            if (!is_numeric($input['salary'])) {
                $errors['salary'] = 'Salary must be numeric.';
            } elseif ((float) $input['salary'] < 0) {
                $errors['salary'] = 'Salary cannot be negative.';
            } elseif ((float) $input['salary'] > 999999999999.99) {
                $errors['salary'] =
                    'Salary exceeds the allowed limit.';
            }
        }

        return $errors;
    }

    /**
     * Retrieve multiple uploaded photos.
     *
     * Frontend FormData key: photos[]
     */
    private function getUploadedPhotos(): array
    {
        $files = $this->request->getFileMultiple('photos');

        if (!$files) {
            return [];
        }

        return array_values(array_filter(
            $files,
            static fn($file) =>
                $file instanceof UploadedFile &&
                $file->getError() !== UPLOAD_ERR_NO_FILE
        ));
    }

    /**
     * Validate uploaded images before moving them.
     */
    private function validatePhotos(array $photos): array
    {
        $errors = [];

        foreach ($photos as $index => $photo) {
            if (!$photo->isValid()) {
                $errors['photos.' . $index] =
                    'One of the uploaded photos is invalid.';
                continue;
            }

            if ($photo->hasMoved()) {
                $errors['photos.' . $index] =
                    'An uploaded photo has already been moved.';
                continue;
            }

            if ($photo->getSize() > self::MAX_PHOTO_SIZE) {
                $errors['photos.' . $index] =
                    'Each photo must be 10 MB or smaller.';
                continue;
            }

            $mimeType = $photo->getMimeType();

            if (!in_array(
                $mimeType,
                self::ALLOWED_PHOTO_TYPES,
                true
            )) {
                $errors['photos.' . $index] =
                    'Only JPEG, PNG, and WEBP images are allowed.';
                continue;
            }

            // Verify that the uploaded file is actually an image.
            $imageInfo = @getimagesize($photo->getTempName());

            if (
                $imageInfo === false ||
                !isset($imageInfo['mime']) ||
                !in_array(
                    $imageInfo['mime'],
                    self::ALLOWED_PHOTO_TYPES,
                    true
                )
            ) {
                $errors['photos.' . $index] =
                    'The uploaded file does not contain a valid supported image.';
            }
        }

        return $errors;
    }

    /**
     * Create the private employee photo directory.
     */
    private function ensureUploadDirectory(): void
    {
        if (!is_dir(self::UPLOAD_DIRECTORY)) {
            if (
                !mkdir(self::UPLOAD_DIRECTORY, 0750, true) &&
                !is_dir(self::UPLOAD_DIRECTORY)
            ) {
                throw new \RuntimeException(
                    'Unable to create employee photo directory.'
                );
            }
        }

        if (!is_writable(self::UPLOAD_DIRECTORY)) {
            throw new \RuntimeException(
                'Employee photo directory is not writable.'
            );
        }
    }

    /**
     * Move one photo and save its metadata and relationship.
     *
     * IMPORTANT:
     * Capture all upload metadata BEFORE moving the file.
     */
    private function saveEmployeePhoto(
        string $employeeId,
        UploadedFile $photo,
        array &$movedFiles
    ): array {
        $attachmentId = $this->generateUuid();

        // Capture metadata before move().
        $originalFileName = $photo->getClientName();
        $mimeType = $photo->getMimeType();
        $fileSize = $photo->getSize();

        $storedName = $photo->getRandomName();

        $photo->move(
            self::UPLOAD_DIRECTORY,
            $storedName
        );

        $absolutePath = self::UPLOAD_DIRECTORY . $storedName;

        $movedFiles[] = $absolutePath;

        // Verify the moved file still exists and is a valid image.
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new \RuntimeException(
                'Uploaded photo could not be saved.'
            );
        }

        $imageInfo = @getimagesize($absolutePath);

        if (
            $imageInfo === false ||
            empty($imageInfo['mime'])
        ) {
            throw new \RuntimeException(
                'Uploaded photo is not a valid image after saving.'
            );
        }

        $actualMime = strtolower($imageInfo['mime']);

        if (!in_array(
            $actualMime,
            self::ALLOWED_PHOTO_TYPES,
            true
        )) {
            throw new \RuntimeException(
                'Uploaded photo has an unsupported image format.'
            );
        }

        // Use the actual detected MIME type and actual saved file size.
        $mimeType = $actualMime;
        $fileSize = filesize($absolutePath);

        if ($fileSize === false) {
            throw new \RuntimeException(
                'Unable to determine saved photo size.'
            );
        }

        $storageKey = self::UPLOAD_STORAGE_PREFIX . $storedName;

        $attachmentData = [
            'id'          => $attachmentId,
            'file_name'   => $originalFileName,
            'storage_key' => $storageKey,
            'mime_type'   => $mimeType,
            'file_size'   => $fileSize,
            'uploaded_by' => null,
        ];

        if (!$this->db->table('attachments')->insert($attachmentData)) {
            throw new \RuntimeException(
                'Unable to save employee photo attachment metadata.'
            );
        }

        $relationshipData = [
            'id'            => $this->generateUuid(),
            'employee_id'   => $employeeId,
            'attachment_id' => $attachmentId,
        ];

        if (
            !$this->db->table('employee_attachments')
                ->insert($relationshipData)
        ) {
            throw new \RuntimeException(
                'Unable to link employee photo to employee.'
            );
        }

        return [
            'id'          => $attachmentId,
            'file_name'   => $originalFileName,
            'storage_key' => $storageKey,
            'mime_type'   => $mimeType,
            'file_size'   => $fileSize,
        ];
    }

    /**
     * Attach photos to employee records.
     */
    private function attachPhotos(array $employees): array
    {
        if (empty($employees)) {
            return [];
        }

        $employeeIds = array_column($employees, 'id');

        $rows = $this->db->table('employee_attachments ea')
            ->select([
                'ea.employee_id',
                'a.id',
                'a.file_name',
                'a.storage_key',
                'a.mime_type',
                'a.file_size',
                'a.created_at',
            ])
            ->join('attachments a', 'a.id = ea.attachment_id')
            ->whereIn('ea.employee_id', $employeeIds)
            ->orderBy('ea.created_at', 'ASC')
            ->get()
            ->getResultArray();

        $photosByEmployee = [];

        foreach ($rows as $row) {
            $employeeId = $row['employee_id'];

            unset($row['employee_id']);

            $photosByEmployee[$employeeId][] = $row;
        }

        foreach ($employees as &$employee) {
            $photos = $photosByEmployee[$employee['id']] ?? [];

            $employee['photos'] = $photos;

            // Backward compatibility.
            $employee['document_photo'] =
                $photos[0]['storage_key'] ?? null;
        }

        unset($employee);

        return $employees;
    }

    /**
     * Check whether a Machine ID is already assigned.
     */
    private function machineIdExists(
        string $machineId,
        ?string $excludeEmployeeId = null
    ): bool {
        $builder = $this->db->table('employees')
            ->where('machine_employee_no', $machineId);

        if ($excludeEmployeeId !== null) {
            $builder->where('id !=', $excludeEmployeeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Find employee by UUID, ERP Employee ID, or Machine ID.
     */
    private function findEmployee(string $identifier): ?array
    {
        $builder = $this->db->table('employees');

        $builder->groupStart()
            ->where('id', $identifier)
            ->orWhere('employee_id', $identifier)
            ->orWhere('machine_employee_no', $identifier)
            ->groupEnd();

        $employee = $builder->get()->getRowArray();

        return $employee ?: null;
    }

    /**
     * Generate UUID.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }

    /**
     * Remove uploaded files if employee creation fails.
     */
    private function removeMovedFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Standard JSON error response.
     */
    private function errorResponse(
        int $status,
        string $message,
        ?string $detail = null
    ): ResponseInterface {
        $data = [
            'message' => $message,
        ];

        if ($detail !== null) {
            $data['detail'] = $detail;
        }

        return $this->response
            ->setStatusCode($status)
            ->setJSON($data);
    }
}
