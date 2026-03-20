<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class Indexes extends \PHPUnit\Framework\TestCase
{
    private static array $entities = ['Zip'];

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->migrate();
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->dropTable();
        }
    }

    public function testUniqueCompoundIndexDuplicateCausesValidationError(): void
    {
        $zipMapper = test_spot_mapper(\SpotTest\Entity\Zip::class);

        $data = [
            'code'  => '12345',
            'city'  => 'Testville',
            'state' => 'NY',
            'lat'   => 1,
            'lng'   => 2,
        ];

        $zip1 = $zipMapper->create($data);
        $zip2 = $zipMapper->build($data);
        $zipMapper->save($zip2);

        $this->assertEmpty($zip1->errors());
        $this->assertNotEmpty($zip2->errors());
    }

    public function testUniqueCompoundIndexNoValidationErrorWhenDataDifferent(): void
    {
        $zipMapper = test_spot_mapper(\SpotTest\Entity\Zip::class);

        $data = [
            'code'  => '23456',
            'city'  => 'Testville',
            'state' => 'NY',
            'lat'   => 1,
            'lng'   => 2,
        ];

        $zip1 = $zipMapper->create($data);

        // Make data slightly different on unique compound index
        $data2 = array_merge($data, ['city' => 'Testville2']);
        $zip2 = $zipMapper->create($data2);

        $this->assertEmpty($zip1->errors());
        $this->assertEmpty($zip2->errors());
    }
}
