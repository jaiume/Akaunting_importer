<?php

namespace App\Processors;

use PDO;

/**
 * Factory class to create the appropriate processor based on processor name
 */
class ProcessorFactory
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a processor instance based on processor name
     * 
     * @param string $processorName e.g., 'rbl_credit_card_csv', 'rbl_bank_pdf'
     * @return BaseProcessor
     * @throws \Exception if processor is not found
     */
    public function create(string $processorName): BaseProcessor
    {
        $processorMap = [
            'rbl_credit_card_csv' => RBLCreditCardCSV::class,
            'rbl_credit_card_pdf' => RBLCreditCardPDF::class,
            'rbl_bank_csv' => RBLBankCSV::class,
            'rbl_bank_pdf' => RBLBankPDF::class,
        ];

        if (!isset($processorMap[$processorName])) {
            throw new \Exception("Unknown processor: {$processorName}");
        }

        $className = $processorMap[$processorName];
        return new $className($this->db);
    }

    /**
     * Get list of available processors
     */
    public static function getAvailableProcessors(): array
    {
        return [
            'rbl_credit_card_csv' => 'RBL Credit Card (CSV)',
            'rbl_credit_card_pdf' => 'RBL Credit Card (PDF)',
            'rbl_bank_csv' => 'RBL Bank (CSV)',
            'rbl_bank_pdf' => 'RBL Bank (PDF)',
        ];
    }

    /**
     * Process a batch by ID
     */
    public function processBatch(int $batchId): array
    {
        // Get batch details
        $stmt = $this->db->prepare("
            SELECT batch_id, import_processor, batch_import_filename 
            FROM import_batches 
            WHERE batch_id = :batch_id
        ");
        $stmt->execute(['batch_id' => $batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new \Exception("Batch not found: {$batchId}");
        }

        if (!$batch['import_processor']) {
            throw new \Exception("No processor defined for batch: {$batchId}");
        }

        // Get file path
        $uploadDir = BASE_DIR . '/' . \App\Services\ConfigService::get('paths.uploads_dir', 'public/uploads');
        $filePath = $uploadDir . '/' . $batch['batch_import_filename'];

        // Create and run processor
        $processor = $this->create($batch['import_processor']);
        $processor->setImportData($batchId, $filePath);

        return $processor->process();
    }
}





