<?php

namespace App\Service;

/**
 * PeriodService
 * 
 * Service pentru gestionarea calculelor de perioadă și date
 * Extrage logica de conversie a perioadelor în date de început
 */
class PeriodService
{
    /**
     * Get start date based on period parameter
     */
    public function getStartDateForPeriod(string $period): \DateTime
    {
        switch ($period) {
            case '1month':
                return new \DateTime('-1 month');
            case '3months':
                return new \DateTime('-3 months');
            case '6months':
                return new \DateTime('-6 months');
            case '1year':
                return new \DateTime('-1 year');
            default:
                return new \DateTime('-6 months'); // Default to 6 months
        }
    }

    /**
     * Get period label for display
     */
    public function getPeriodLabel(string $period): string
    {
        return match($period) {
            '1month' => '1 Month',
            '3months' => '3 Months',
            '6months' => '6 Months',
            '1year' => '1 Year',
            default => '6 Months'
        };
    }

    /**
     * Get all available periods
     */
    public function getAvailablePeriods(): array
    {
        return [
            '1month' => '1 Month',
            '3months' => '3 Months',
            '6months' => '6 Months',
            '1year' => '1 Year'
        ];
    }

    /**
     * Validate period parameter
     */
    public function isValidPeriod(string $period): bool
    {
        return in_array($period, array_keys($this->getAvailablePeriods()));
    }

    /**
     * Get default period
     */
    public function getDefaultPeriod(): string
    {
        return '6months';
    }

    /**
     * Get date range for a period
     */
    public function getDateRange(string $period): array
    {
        $startDate = $this->getStartDateForPeriod($period);
        $endDate = new \DateTime();

        return [
            'start' => $startDate,
            'end' => $endDate,
            'period' => $period,
            'label' => $this->getPeriodLabel($period)
        ];
    }

    /**
     * Get months between two dates
     */
    public function getMonthsBetween(\DateTime $startDate, \DateTime $endDate): array
    {
        $months = [];
        $current = clone $startDate;
        
        while ($current <= $endDate) {
            $months[] = $current->format('Y-m');
            $current->modify('+1 month');
        }
        
        return $months;
    }

    /**
     * Format date for SQL queries
     */
    public function formatDateForSql(\DateTime $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
