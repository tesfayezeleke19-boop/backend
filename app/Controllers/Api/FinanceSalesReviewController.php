<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class FinanceSalesReviewController extends BaseController
{
    private const PENDING = 'PENDING_FINANCE_REVIEW';
    private const APPROVED = 'FINANCE_APPROVED';
    private const REJECTED = 'REJECTED';

    /**
     * GET /api/sales/finance/sales-review
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();

        $invoices = $db->table('sales_invoices')
            ->where('status', self::PENDING)
            ->orderBy('submitted_at', 'ASC')
            ->get()
            ->getResultArray();

        $data = [];

        foreach ($invoices as $invoice) {
            $lines = $db->table('sales_invoice_lines')
                ->where('invoice_id', $invoice['id'])
                ->orderBy('order_number', 'ASC')
                ->get()
                ->getResultArray();

            $data[] = $this->formatInvoice($invoice, $lines);
        }

        return $this->response->setJSON([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * POST /api/sales/finance/sales-review/{id}/approve
     */
    public function approve(string $id): ResponseInterface
    {
        return $this->changeStatus($id, self::APPROVED);
    }

    /**
     * POST /api/sales/finance/sales-review/{id}/reject
     */
    public function reject(string $id): ResponseInterface
    {
        $body = $this->request->getJSON(true);
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($reason === '') {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'success' => false,
                    'message' => 'A rejection reason is required.',
                ]);
        }

        return $this->changeStatus($id, self::REJECTED, $reason);
    }

    private function changeStatus(
        string $id,
        string $newStatus,
        ?string $reason = null
    ): ResponseInterface {
        $db = db_connect();

        try {
            $db->transBegin();

            // Lock the invoice to prevent concurrent reviews.
            $invoice = $db->query(
                'SELECT * FROM sales_invoices WHERE id = ? FOR UPDATE',
                [$id]
            )->getRowArray();

            if (!$invoice) {
                $db->transRollback();

                return $this->response
                    ->setStatusCode(404)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Invoice not found.',
                    ]);
            }

            if ($invoice['status'] !== self::PENDING) {
                $db->transRollback();

                return $this->response
                    ->setStatusCode(409)
                    ->setJSON([
                        'success' => false,
                        'message' => 'This invoice is no longer pending Finance review.',
                        'currentStatus' => $invoice['status'],
                    ]);
            }

            $now = date('Y-m-d H:i:s');

            $update = [
                'status' => $newStatus,
                'finance_reviewed_at' => $now,
                'updated_at' => $now,
                'version' => ((int) $invoice['version']) + 1,
            ];

            if ($newStatus === self::APPROVED) {
                $update['approved_at'] = $now;
                $update['rejection_reason'] = null;
            } else {
                $update['rejected_at'] = $now;
                $update['rejection_reason'] = $reason;
                $update['approved_at'] = null;
                $update['approved_by'] = null;
            }

            $db->table('sales_invoices')
                ->where('id', $id)
                ->update($update);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Database update failed.');
            }

            $db->transCommit();

            // Reload the invoice and its lines after the update.
            $updated = $db->table('sales_invoices')
                ->where('id', $id)
                ->get()
                ->getRowArray();

            $lines = $db->table('sales_invoice_lines')
                ->where('invoice_id', $id)
                ->orderBy('order_number', 'ASC')
                ->get()
                ->getResultArray();

            return $this->response->setJSON([
                'success' => true,
                'message' => $newStatus === self::APPROVED
                    ? 'Invoice approved successfully.'
                    : 'Invoice rejected successfully.',
                'data' => $this->formatInvoice($updated, $lines),
            ]);
        } catch (Throwable $e) {
            if ($db->transStatus() !== false) {
                $db->transRollback();
            } else {
                $db->transRollback();
            }

            log_message('error', 'Finance invoice review failed: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => 'Unable to update the invoice. Please try again.',
                ]);
        }
    }

    private function formatInvoice(array $invoice, array $lines): array
    {
        $orders = [];

        foreach ($lines as $line) {
            $orders[] = [
                'id' => $line['id'],
                'orderNumber' => (int) $line['order_number'],
                'aggregate' => $line['aggregate'],
                'quantity' => (float) $line['quantity'],
                'unit' => $line['unit'],
                'unitPrice' => (float) $line['unit_price'],
                'lineTotalExclVat' => (float) $line['line_total_excl_vat'],
                'royaltyOrderNumber' => (int) $line['royalty_order_number'],
                'royaltyInvoiceNumber' => $line['royalty_invoice_number'],
                'royaltyAmount' => (float) $line['royalty_amount'],
                'contractOrderId' => $line['contract_order_id'],
                'orderDate' => $line['order_date'],
            ];
        }

        $subtotal = (float) $invoice['subtotal_excl_vat'];
        $vat = (float) $invoice['total_vat'];
        $total = (float) $invoice['total_price_incl_vat'];
        $withholding = (float) $invoice['withholding_amount'];

        return [
            'id' => $invoice['id'],
            'invoiceNumber' => $invoice['invoice_number'],
            'date' => $invoice['invoice_date'],
            'customer' => [
                'name' => $invoice['customer_name_snapshot'],
                'tin' => $invoice['customer_tin_snapshot'],
            ],
            'fsNo' => $invoice['fs_no'],
            'contractId' => $invoice['contract_id'],
            'orders' => $orders,
            'financialSummary' => [
                'subtotalExclVat' => $subtotal,
                'totalVat' => $vat,
                'totalPriceInclVat' => $total,
                'withholdingEnabled' => (bool) $invoice['withholding_enabled'],
                'withholdingRate' => (float) $invoice['withholding_rate'],
                'withholdingAmount' => $withholding,
                'netAmountAfterWithholding' =>
                    (float) $invoice['net_amount_after_withholding'],
            ],
            'payment' => [
                'bank' => $invoice['bank'] ?? '',
                'paymentSender' => $invoice['payment_sender'] ?? '',
                'paymentReferenceNumber' =>
                    $invoice['payment_reference_number'] ?? '',
                'paymentEvidence' => null,
            ],
            'withholdingEvidence' => null,
            'remark' => $invoice['remark'],
            'status' => $invoice['status'],
            'rejectionReason' => $invoice['rejection_reason'],
            'createdAt' => $invoice['created_at'],
            'updatedAt' => $invoice['updated_at'],
            'submittedAt' => $invoice['submitted_at'],
            'financeReviewedAt' => $invoice['finance_reviewed_at'],
            'approvedAt' => $invoice['approved_at'],
            'approvedBy' => $invoice['approved_by'],
            'rejectedAt' => $invoice['rejected_at'],
            'paymentConfirmedAt' => $invoice['payment_confirmed_at'],
            'paymentConfirmedBy' => $invoice['payment_confirmed_by'],
            'createdBy' => $invoice['created_by'],
            'submittedBy' => $invoice['submitted_by'],
        ];
    }
}
