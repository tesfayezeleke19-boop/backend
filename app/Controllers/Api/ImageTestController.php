<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

class ImageTestController extends BaseController
{
    private $db;

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * POST /api/image-test/upload
     *
     * Upload an image and store its Base64 content in PostgreSQL.
     */
    public function upload(): ResponseInterface
    {
        try {
            $file = $this->request->getFile('image');

            if (!$file || !$file->isValid() || $file->hasMoved()) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Please select a valid image.',
                    ]);
            }

            $fileSize = (int) $file->getSize();

            if ($fileSize <= 0 || $fileSize > self::MAX_FILE_SIZE) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Image must be between 1 byte and 10 MB.',
                    ]);
            }

            $tempPath = $file->getTempName();

            if (!is_file($tempPath)) {
                throw new \RuntimeException('Temporary upload file does not exist.');
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $imageInfo = getimagesize($file->getTempName());

if ($imageInfo === false || empty($imageInfo['mime'])) {
    return $this->response
        ->setStatusCode(415)
        ->setJSON([
            'success' => false,
            'message' => 'The uploaded file is not a valid image.',
        ]);
}

$mimeType = $imageInfo['mime'];

if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
    return $this->response
        ->setStatusCode(415)
        ->setJSON([
            'success' => false,
            'message' => 'Unsupported image format.',
            'mime_type' => $mimeType,
        ]);
}

            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Only JPEG, PNG, WebP, and GIF images are allowed.',
                        'detectedMimeType' => $mimeType,
                    ]);
            }

            $imageBytes = file_get_contents($tempPath);

            if ($imageBytes === false || strlen($imageBytes) !== $fileSize) {
                throw new \RuntimeException('Could not read the complete uploaded image.');
            }

            $base64 = base64_encode($imageBytes);
            $sha256 = hash('sha256', $imageBytes);
            $fileName = basename((string) $file->getClientName());

            $this->db->table('image_test_files')->insert([
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'file_size' => strlen($imageBytes),
                'image_base64' => $base64,
                'sha256' => $sha256,
            ]);

            $id = (int) $this->db->insertID();

            return $this->response
                ->setStatusCode(201)
                ->setJSON([
                    'success' => true,
                    'message' => 'Image uploaded and stored in PostgreSQL.',
                    'data' => [
                        'id' => $id,
                        'fileName' => $fileName,
                        'mimeType' => $mimeType,
                        'fileSize' => strlen($imageBytes),
                        'sha256' => $sha256,
                    ],
                ]);
        } catch (Throwable $e) {
            log_message('error', 'Image test upload failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Image upload failed.',
                    'error' => $e->getMessage(),
                ]);
        }
    }

    /**
     * GET /api/image-test/images
     *
     * Return image metadata, not the image content.
     */
    public function index(): ResponseInterface
    {
        try {
            $images = $this->db->table('image_test_files')
                ->select('id, file_name, mime_type, file_size, sha256, created_at')
                ->orderBy('id', 'DESC')
                ->get()
                ->getResultArray();

            return $this->response->setJSON([
                'success' => true,
                'data' => $images,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Image test list failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Could not retrieve image list.',
                    'error' => $e->getMessage(),
                ]);
        }
    }

    /**
     * GET /api/image-test/images/{id}
     *
     * Retrieve the original image bytes from PostgreSQL.
     */
    public function show($id = null): ResponseInterface
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return $this->response->setStatusCode(400)->setBody('Invalid image ID.');
        }

        try {
            $image = $this->db->table('image_test_files')
                ->select('file_name, mime_type, file_size, image_base64, sha256')
                ->where('id', (int) $id)
                ->get()
                ->getRowArray();

            if (!$image) {
                return $this->response->setStatusCode(404)->setBody('Image not found.');
            }

            $imageBytes = base64_decode($image['image_base64'], true);

            if ($imageBytes === false) {
                return $this->response
                    ->setStatusCode(500)
                    ->setBody('Stored image Base64 data is invalid.');
            }

            $actualSize = strlen($imageBytes);
            $actualHash = hash('sha256', $imageBytes);

            return $this->response
                ->setStatusCode(200)
                ->setContentType($image['mime_type'])
                ->setHeader('Content-Length', (string) $actualSize)
                ->setHeader('Content-Disposition', 'inline; filename="' .
                    str_replace('"', '', $image['file_name']) . '"')
                ->setHeader('X-Image-SHA256', $actualHash)
                ->setHeader('X-Image-Expected-Size', (string) $image['file_size'])
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->setBody($imageBytes);
        } catch (Throwable $e) {
            log_message('error', 'Image test retrieval failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setBody('Could not retrieve image.');
        }
    }
}
