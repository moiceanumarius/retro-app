<?php

namespace App\Tests\Service;

use App\Service\PeriodService;
use PHPUnit\Framework\TestCase;

class PeriodServiceTest extends TestCase
{
    private PeriodService $service;

    protected function setUp(): void
    {
        $this->service = new PeriodService();
    }

    public function testGetStartDateForValidPeriods(): void
    {
        $now = new \DateTime();
        
        // Test 1 month
        $date1Month = $this->service->getStartDateForPeriod('1month');
        $expected1Month = new \DateTime('-1 month');
        $this->assertEquals($expected1Month->format('Y-m-d'), $date1Month->format('Y-m-d'));
        
        // Test 3 months
        $date3Months = $this->service->getStartDateForPeriod('3months');
        $expected3Months = new \DateTime('-3 months');
        $this->assertEquals($expected3Months->format('Y-m-d'), $date3Months->format('Y-m-d'));
        
        // Test 6 months (default)
        $date6Months = $this->service->getStartDateForPeriod('6months');
        $expected6Months = new \DateTime('-6 months');
        $this->assertEquals($expected6Months->format('Y-m-d'), $date6Months->format('Y-m-d'));
        
        // Test 1 year
        $date1Year = $this->service->getStartDateForPeriod('1year');
        $expected1Year = new \DateTime('-1 year');
        $this->assertEquals($expected1Year->format('Y-m-d'), $date1Year->format('Y-m-d'));
    }

    public function testGetStartDateForInvalidPeriodReturnsDefault(): void
    {
        $date = $this->service->getStartDateForPeriod('invalid');
        $expected = new \DateTime('-6 months');
        
        $this->assertEquals($expected->format('Y-m-d'), $date->format('Y-m-d'));
    }

    public function testGetPeriodLabel(): void
    {
        $this->assertEquals('1 Month', $this->service->getPeriodLabel('1month'));
        $this->assertEquals('3 Months', $this->service->getPeriodLabel('3months'));
        $this->assertEquals('6 Months', $this->service->getPeriodLabel('6months'));
        $this->assertEquals('1 Year', $this->service->getPeriodLabel('1year'));
        $this->assertEquals('6 Months', $this->service->getPeriodLabel('invalid'));
    }

    public function testGetAvailablePeriods(): void
    {
        $periods = $this->service->getAvailablePeriods();
        
        $this->assertIsArray($periods);
        $this->assertCount(4, $periods);
        $this->assertArrayHasKey('1month', $periods);
        $this->assertArrayHasKey('3months', $periods);
        $this->assertArrayHasKey('6months', $periods);
        $this->assertArrayHasKey('1year', $periods);
        
        $this->assertEquals('1 Month', $periods['1month']);
        $this->assertEquals('3 Months', $periods['3months']);
        $this->assertEquals('6 Months', $periods['6months']);
        $this->assertEquals('1 Year', $periods['1year']);
    }

    public function testIsValidPeriod(): void
    {
        $this->assertTrue($this->service->isValidPeriod('1month'));
        $this->assertTrue($this->service->isValidPeriod('3months'));
        $this->assertTrue($this->service->isValidPeriod('6months'));
        $this->assertTrue($this->service->isValidPeriod('1year'));
        
        $this->assertFalse($this->service->isValidPeriod('invalid'));
        $this->assertFalse($this->service->isValidPeriod('2months'));
        $this->assertFalse($this->service->isValidPeriod(''));
    }

    public function testGetDefaultPeriod(): void
    {
        $this->assertEquals('6months', $this->service->getDefaultPeriod());
    }

    public function testGetDateRange(): void
    {
        $range = $this->service->getDateRange('3months');
        
        $this->assertIsArray($range);
        $this->assertArrayHasKey('start', $range);
        $this->assertArrayHasKey('end', $range);
        $this->assertArrayHasKey('period', $range);
        $this->assertArrayHasKey('label', $range);
        
        $this->assertInstanceOf(\DateTime::class, $range['start']);
        $this->assertInstanceOf(\DateTime::class, $range['end']);
        $this->assertEquals('3months', $range['period']);
        $this->assertEquals('3 Months', $range['label']);
        
        // Verify dates are reasonable
        $expected = new \DateTime('-3 months');
        $this->assertEquals($expected->format('Y-m-d'), $range['start']->format('Y-m-d'));
        
        $now = new \DateTime();
        $this->assertEquals($now->format('Y-m-d'), $range['end']->format('Y-m-d'));
    }

    public function testGetMonthsBetween(): void
    {
        $start = new \DateTime('2024-01-15');
        $end = new \DateTime('2024-04-20');
        
        $months = $this->service->getMonthsBetween($start, $end);
        
        $this->assertIsArray($months);
        $this->assertCount(4, $months);
        $this->assertEquals('2024-01', $months[0]);
        $this->assertEquals('2024-02', $months[1]);
        $this->assertEquals('2024-03', $months[2]);
        $this->assertEquals('2024-04', $months[3]);
    }

    public function testGetMonthsBetweenSameMonth(): void
    {
        $start = new \DateTime('2024-01-01');
        $end = new \DateTime('2024-01-31');
        
        $months = $this->service->getMonthsBetween($start, $end);
        
        $this->assertIsArray($months);
        $this->assertCount(1, $months);
        $this->assertEquals('2024-01', $months[0]);
    }

    public function testGetMonthsBetweenAcrossYear(): void
    {
        $start = new \DateTime('2023-11-01');
        $end = new \DateTime('2024-02-15');
        
        $months = $this->service->getMonthsBetween($start, $end);
        
        $this->assertIsArray($months);
        $this->assertCount(4, $months);
        $this->assertEquals('2023-11', $months[0]);
        $this->assertEquals('2023-12', $months[1]);
        $this->assertEquals('2024-01', $months[2]);
        $this->assertEquals('2024-02', $months[3]);
    }

    public function testFormatDateForSql(): void
    {
        $date = new \DateTime('2024-03-15 14:30:45');
        $formatted = $this->service->formatDateForSql($date);
        
        $this->assertEquals('2024-03-15 14:30:45', $formatted);
    }

    public function testFormatDateForSqlWithTimezone(): void
    {
        $date = new \DateTime('2024-03-15 14:30:45', new \DateTimeZone('UTC'));
        $formatted = $this->service->formatDateForSql($date);
        
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $formatted);
        $this->assertEquals('2024-03-15 14:30:45', $formatted);
    }
}

