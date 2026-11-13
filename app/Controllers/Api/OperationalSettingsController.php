<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class OperationalSettingsController extends BaseController

{
    private const ALLOWED_SETTINGS = [
        'aggregate_price_per_m3' => [
            'min' => 0,
            'max' => 1000000000,
        ],
        'overtime_rate' => [
            'min' => 100,
            'max' => 1000,
        ],
        'standard_working_hours' => [
            'min' => 0.01,
            'max' => 24,
        ],
    ];

    private function db()
    {
        return db_connect();
    }

    private function actor(): ?array
    {
        $authorization = trim(
            (string) $this->request->getHeaderLine('Authorization')
        );

        if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return null;
        }

        $tokenHash = hash('sha256', $matches[1]);

        return $this->db()->table('user_sessions s')
            ->select('u.id, u.email, u.role, u.account_status')
            ->join('users u', 'u.id = s.user_id')
            ->where('s.token_hash', $tokenHash)
            ->where('s.revoked_at', null)
            ->where('s.expires_at >', date('Y-m-d H:i:s'))
            ->where('LOWER(u.account_status)', 'active')
            ->get()
            ->getRowArray();
    }

    private function adminRequired(): ?ResponseInterface
    {
        $actor = $this->actor();

        if (!$actor) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'success' => false,
                    'message' => 'Authentication required.',
                ]);
        }

        if (strtoupper((string) $actor['role']) !== 'ADMIN') {
            return $this->response
                ->setStatusCode(403)
                ->setJSON([
                    'success' => false,
                    'message' => 'Administrator permission required.',
                ]);
        }

        return null;
    }

    private function formatSetting(array $setting): array
    {
        return [
            'id' => (string) $setting['id'],
            'settingKey' => (string) $setting['setting_key'],
            'settingName' => (string) $setting['setting_name'],
            'settingValue' => (float) $setting['setting_value'],
            'unit' => (string) ($setting['unit'] ?? ''),
            'description' => (string) ($setting['description'] ?? ''),
            'updatedAt' => strtotime($setting['updated_at']) * 1000,
        ];
    }

    public function index(): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $settings = $this->db()->table('operational_settings')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'data' => array_map(
                fn ($setting) => $this->formatSetting($setting),
                $settings
            ),
        ]);
    }

    public function update($id = null): ResponseInterface
    {
        if ($error = $this->adminRequired()) {
            return $error;
        }

        $actor = $this->actor();
        $data = $this->request->getJSON(true);

        if (!is_array($data) || !isset($data['settings']) || !is_array($data['settings'])) {
            return $this->response->setStatusCode(400)->setJSON([
                'success' => false,
                'message' => 'A settings object is required.',
            ]);
        }

        $submittedSettings = $data['settings'];

        if (empty($submittedSettings)) {
            return $this->response->setStatusCode(422)->setJSON([
                'success' => false,
                'message' => 'At least one setting must be provided.',
            ]);
        }

        $validated = [];

        foreach ($submittedSettings as $key => $value) {
            if (!array_key_exists($key, self::ALLOWED_SETTINGS)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => "Unsupported setting: {$key}.",
                ]);
            }

            if (!is_numeric($value)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => "The value for {$key} must be numeric.",
                ]);
            }

            $numericValue = (float) $value;
            $limits = self::ALLOWED_SETTINGS[$key];

            if (
                !is_finite($numericValue)
                || $numericValue < $limits['min']
                || $numericValue > $limits['max']
            ) {
                return $this->response->setStatusCode(422)->setJSON([
                    'success' => false,
                    'message' => "The value for {$key} is outside the allowed range.",
                ]);
            }

            $validated[$key] = $numericValue;
        }

        $db = $this->db();

        // Make sure every submitted key already exists in the settings table.
        $existingRows = $db->table('operational_settings')
            ->whereIn('setting_key', array_keys($validated))
            ->get()
            ->getResultArray();

        $existingKeys = array_column($existingRows, 'setting_key');

        foreach (array_keys($validated) as $key) {
            if (!in_array($key, $existingKeys, true)) {
                return $this->response->setStatusCode(404)->setJSON([
                    'success' => false,
                    'message' => "Setting '{$key}' was not found in the database.",
                ]);
            }
        }

        $now = date('Y-m-d H:i:s');

        $db->transBegin();

        foreach ($validated as $key => $value) {
            $db->table('operational_settings')
                ->where('setting_key', $key)
                ->update([
                    'setting_value' => $value,
                    'updated_at' => $now,
                    'updated_by' => $actor['id'],
                ]);
        }

        if ($db->transStatus() === false) {
            $db->transRollback();

            return $this->response->setStatusCode(500)->setJSON([
                'success' => false,
                'message' => 'Could not update operational settings.',
            ]);
        }

        $db->transCommit();

        $settings = $db->table('operational_settings')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Operational settings updated successfully.',
            'data' => array_map(
                fn ($setting) => $this->formatSetting($setting),
                $settings
            ),
        ]);
    }


    private function formatAggregateType(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'pricePerM3' => (float) $row['price_per_m3'],
        'description' => (string) ($row['description'] ?? ''),
        'isActive' => (bool) $row['is_active'],
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ];
}

public function aggregateTypes(): ResponseInterface
{
    if ($error = $this->adminRequired()) {
        return $error;
    }

    $rows = $this->db()->table('aggregate_types')
        ->orderBy('id', 'DESC')
        ->get()
        ->getResultArray();

    return $this->response->setJSON([
        'success' => true,
        'data' => array_map(
            fn ($row) => $this->formatAggregateType($row),
            $rows
        ),
    ]);
}

public function createAggregateType(): ResponseInterface
{
    if ($error = $this->adminRequired()) {
        return $error;
    }

    $actor = $this->actor();
    $data = $this->request->getJSON(true);

    if (!is_array($data)) {
        return $this->response->setStatusCode(400)->setJSON([
            'success' => false,
            'message' => 'Invalid request data.',
        ]);
    }

    $name = trim((string) ($data['name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $price = $data['pricePerM3'] ?? null;

    if ($name === '' || !is_numeric($price)) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Name and a valid price per m³ are required.',
        ]);
    }

    $price = (float) $price;

    if (!is_finite($price) || $price < 0 || $price > 1000000000) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Price per m³ is outside the allowed range.',
        ]);
    }

    $db = $this->db();

    $existing = $db->table('aggregate_types')
        ->where('LOWER(name)', strtolower($name))
        ->countAllResults();

    if ($existing > 0) {
        return $this->response->setStatusCode(409)->setJSON([
            'success' => false,
            'message' => 'An aggregate type with this name already exists.',
        ]);
    }

    $now = date('Y-m-d H:i:s');

    $db->table('aggregate_types')->insert([
        'name' => $name,
        'price_per_m3' => $price,
        'description' => $description,
        'is_active' => true,
        'created_by' => $actor['id'],
        'updated_by' => $actor['id'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if ($db->affectedRows() < 1) {
        return $this->response->setStatusCode(500)->setJSON([
            'success' => false,
            'message' => 'Could not create aggregate type.',
        ]);
    }

    $id = $db->insertID();

    $row = $db->table('aggregate_types')
        ->where('id', $id)
        ->get()
        ->getRowArray();

    return $this->response->setStatusCode(201)->setJSON([
        'success' => true,
        'message' => 'Aggregate type created successfully.',
        'data' => $this->formatAggregateType($row),
    ]);
}

public function updateAggregateType($id = null): ResponseInterface
{
    if ($error = $this->adminRequired()) {
        return $error;
    }

    if (!is_numeric($id) || (int) $id < 1) {
        return $this->response->setStatusCode(400)->setJSON([
            'success' => false,
            'message' => 'Invalid aggregate type ID.',
        ]);
    }

    $data = $this->request->getJSON(true);

    if (!is_array($data)) {
        return $this->response->setStatusCode(400)->setJSON([
            'success' => false,
            'message' => 'Invalid request data.',
        ]);
    }

    $name = trim((string) ($data['name'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $price = $data['pricePerM3'] ?? null;

    if ($name === '' || !is_numeric($price)) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Name and a valid price per m³ are required.',
        ]);
    }

    $price = (float) $price;

    if (!is_finite($price) || $price < 0 || $price > 1000000000) {
        return $this->response->setStatusCode(422)->setJSON([
            'success' => false,
            'message' => 'Price per m³ is outside the allowed range.',
        ]);
    }

    $db = $this->db();

    $existing = $db->table('aggregate_types')
        ->where('id', (int) $id)
        ->get()
        ->getRowArray();

    if (!$existing) {
        return $this->response->setStatusCode(404)->setJSON([
            'success' => false,
            'message' => 'Aggregate type not found.',
        ]);
    }

    $duplicate = $db->table('aggregate_types')
        ->where('LOWER(name)', strtolower($name))
        ->where('id !=', (int) $id)
        ->countAllResults();

    if ($duplicate > 0) {
        return $this->response->setStatusCode(409)->setJSON([
            'success' => false,
            'message' => 'Another aggregate type already uses this name.',
        ]);
    }

    $db->table('aggregate_types')
        ->where('id', (int) $id)
        ->update([
            'name' => $name,
            'price_per_m3' => $price,
            'description' => $description,
            'updated_by' => $this->actor()['id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

    $row = $db->table('aggregate_types')
        ->where('id', (int) $id)
        ->get()
        ->getRowArray();

    return $this->response->setJSON([
        'success' => true,
        'message' => 'Aggregate type updated successfully.',
        'data' => $this->formatAggregateType($row),
    ]);
}

public function deleteAggregateType($id = null): ResponseInterface
{
    if ($error = $this->adminRequired()) {
        return $error;
    }

    if (!is_numeric($id) || (int) $id < 1) {
        return $this->response->setStatusCode(400)->setJSON([
            'success' => false,
            'message' => 'Invalid aggregate type ID.',
        ]);
    }

    $db = $this->db();

    $existing = $db->table('aggregate_types')
        ->where('id', (int) $id)
        ->get()
        ->getRowArray();

    if (!$existing) {
        return $this->response->setStatusCode(404)->setJSON([
            'success' => false,
            'message' => 'Aggregate type not found.',
        ]);
    }

    $db->table('aggregate_types')
        ->where('id', (int) $id)
        ->delete();

    return $this->response->setJSON([
        'success' => true,
        'message' => 'Aggregate type deleted successfully.',
    ]);
}
}
