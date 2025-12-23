<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\ReportService;
use App\Services\InstallationService;

class ReportController extends BaseController
{
    private ReportService $reportService;
    private InstallationService $installationService;

    public function __construct(
        Twig $view,
        ReportService $reportService,
        InstallationService $installationService
    ) {
        parent::__construct($view);
        $this->reportService = $reportService;
        $this->installationService = $installationService;
    }

    /**
     * Show the income/expenses report form
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        
        // Get all installations for the user
        $installations = $this->installationService->getInstallationsByUser($user['user_id']);
        
        // Default to current month
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        
        return $this->render($response, 'reports/income_expenses.html.twig', [
            'user' => $user,
            'installations' => $installations,
            'current_year' => $currentYear,
            'current_month' => $currentMonth,
            'years' => range($currentYear - 5, $currentYear + 1), // Last 5 years + next year
            'months' => [
                1 => 'January',
                2 => 'February',
                3 => 'March',
                4 => 'April',
                5 => 'May',
                6 => 'June',
                7 => 'July',
                8 => 'August',
                9 => 'September',
                10 => 'October',
                11 => 'November',
                12 => 'December'
            ]
        ]);
    }

    /**
     * Generate and display the report
     */
    public function generate(Request $request, Response $response): Response
    {
        $user = $this->getUser($request);
        $data = $this->getPostData($request);
        
        $installationId = (int)($data['installation_id'] ?? 0);
        $year = (int)($data['year'] ?? date('Y'));
        $month = (int)($data['month'] ?? date('n'));
        
        // Validate inputs
        $errors = [];
        if ($installationId <= 0) {
            $errors[] = 'Please select an Akaunting installation';
        }
        if ($year < 2000 || $year > 2100) {
            $errors[] = 'Invalid year selected';
        }
        if ($month < 1 || $month > 12) {
            $errors[] = 'Invalid month selected';
        }
        
        // Get installations for form re-display
        $installations = $this->installationService->getInstallationsByUser($user['user_id']);
        $currentYear = (int)date('Y');
        
        if (!empty($errors)) {
            return $this->render($response, 'reports/income_expenses.html.twig', [
                'user' => $user,
                'installations' => $installations,
                'current_year' => $currentYear,
                'current_month' => (int)date('n'),
                'years' => range($currentYear - 5, $currentYear + 1),
                'months' => $this->getMonthNames(),
                'errors' => $errors,
                'selected_installation' => $installationId,
                'selected_year' => $year,
                'selected_month' => $month
            ]);
        }
        
        try {
            // Generate the report
            $report = $this->reportService->generateIncomeExpenseReport(
                $installationId,
                $user['user_id'],
                $year,
                $month
            );
            
            return $this->render($response, 'reports/income_expenses.html.twig', [
                'user' => $user,
                'installations' => $installations,
                'current_year' => $currentYear,
                'current_month' => (int)date('n'),
                'years' => range($currentYear - 5, $currentYear + 1),
                'months' => $this->getMonthNames(),
                'report' => $report,
                'selected_installation' => $installationId,
                'selected_year' => $year,
                'selected_month' => $month
            ]);
            
        } catch (\Exception $e) {
            return $this->render($response, 'reports/income_expenses.html.twig', [
                'user' => $user,
                'installations' => $installations,
                'current_year' => $currentYear,
                'current_month' => (int)date('n'),
                'years' => range($currentYear - 5, $currentYear + 1),
                'months' => $this->getMonthNames(),
                'errors' => ['Failed to generate report: ' . $e->getMessage()],
                'selected_installation' => $installationId,
                'selected_year' => $year,
                'selected_month' => $month
            ]);
        }
    }

    /**
     * Get month names array
     */
    private function getMonthNames(): array
    {
        return [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December'
        ];
    }
}


