<?php

namespace Tests\Unit;

use App\Support\ReservationInput;
use PHPUnit\Framework\TestCase;

class ReservationInputTest extends TestCase
{
    private function normalize(array $input): array
    {
        return ReservationInput::normalize($input);
    }

    public function test_na_phone_becomes_null(): void
    {
        $this->assertNull($this->normalize(['phone' => 'NA'])['phone']);
    }

    public function test_phone_is_reduced_to_digits(): void
    {
        $this->assertSame('082249803564', $this->normalize(['phone' => '0822-4980-3564'])['phone']);
    }

    public function test_phone_with_spaces_is_reduced_to_digits(): void
    {
        $this->assertSame('081294489888', $this->normalize(['phone' => '0812 9448 9888'])['phone']);
    }

    public function test_na_email_becomes_null(): void
    {
        $this->assertNull($this->normalize(['email' => 'NA'])['email']);
    }

    public function test_email_is_lowercased_and_trimmed(): void
    {
        $this->assertSame('ira@umara.id', $this->normalize(['email' => '  IRA@Umara.ID '])['email']);
    }

    public function test_guest_name_is_trimmed(): void
    {
        $this->assertSame('Bapak Wanda', $this->normalize(['guest_name' => '  Bapak Wanda  '])['guest_name']);
    }

    public function test_single_start_time_is_normalized(): void
    {
        $out = $this->normalize(['start_time' => '11.00']);

        $this->assertSame('11:00', $out['start_time']);
        $this->assertNull($out['end_time']);
    }

    public function test_range_typed_into_start_time_is_split(): void
    {
        $out = $this->normalize(['start_time' => '12.00-15.00']);

        $this->assertSame('12:00', $out['start_time']);
        $this->assertSame('15:00', $out['end_time']);
    }

    public function test_explicit_end_time_wins_over_split_result(): void
    {
        $out = $this->normalize(['start_time' => '12.00', 'end_time' => '14.30']);

        $this->assertSame('12:00', $out['start_time']);
        $this->assertSame('14:30', $out['end_time']);
    }

    public function test_time_from_a_time_picker_is_accepted(): void
    {
        $out = $this->normalize(['start_time' => '12:00:00']);

        $this->assertSame('12:00', $out['start_time']);
    }

    public function test_blank_status_becomes_null(): void
    {
        $this->assertNull($this->normalize(['status' => ''])['status']);
    }

    public function test_blank_remark_becomes_null(): void
    {
        $this->assertNull($this->normalize(['remark' => '   '])['remark']);
    }

    public function test_missing_keys_are_left_untouched(): void
    {
        $out = $this->normalize(['pax' => 5, 'pic_id' => 3]);

        $this->assertSame(5, $out['pax']);
        $this->assertSame(3, $out['pic_id']);
    }

    public function test_unknown_keys_pass_through(): void
    {
        $out = $this->normalize(['area_id' => 2]);

        $this->assertSame(2, $out['area_id']);
    }
}
