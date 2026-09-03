<?php

namespace Fhp\Tests\Unit\Action;

use Fhp\Action\GetStatementOfAccount;
use Fhp\Model\SEPAAccount;
use Fhp\Protocol\BPD;
use Fhp\Segment\BaseSegment;
use Fhp\Segment\KAZ\HIKAZSv5;
use Fhp\Segment\KAZ\HIKAZSv6;
use Fhp\Segment\KAZ\HIKAZSv7;
use Fhp\Segment\KAZ\HKKAZv5;
use Fhp\Segment\KAZ\HKKAZv7;
use Fhp\Segment\KAZ\ParameterKontoumsaetzeV2;
use Fhp\Segment\SPA\HISPASv1;
use Fhp\Segment\SPA\ParameterSepaKontoverbindungAnfordernV1;

class GetStatementOfAccountTest extends \PHPUnit\Framework\TestCase
{
    public function testRejectsReversedDateRange()
    {
        $this->expectException(\InvalidArgumentException::class);
        GetStatementOfAccount::create(
            self::account(),
            new \DateTimeImmutable('2026-07-19'),
            new \DateTimeImmutable('2026-04-20')
        );
    }

    public function testCreateRequestBuildsHkkazV5FromDateTimeInterface()
    {
        $action = GetStatementOfAccount::create(
            self::account(),
            new \DateTimeImmutable('2026-04-20'),
            new \DateTimeImmutable('2026-07-19'),
            true
        );

        $requestSegments = $action->getNextRequest(self::createBpd(self::createHikazsSegment(5, true)), null);

        $this->assertCount(1, $requestSegments);
        /** @var HKKAZv5 $request */
        $request = $requestSegments[0];
        self::assertInstanceOf(HKKAZv5::class, $request);
        self::assertTrue($request->alleKonten);
        self::assertSame('20260420', $request->vonDatum);
        self::assertSame('20260719', $request->bisDatum);
        self::assertSame('5407324931', $request->kontoverbindungAuftraggeber->kontonummer);
        self::assertSame('50010517', $request->kontoverbindungAuftraggeber->kik->kreditinstitutscode);
    }

    public function testCreateRequestBuildsHkkazV7FromDateTimeInterface()
    {
        $action = GetStatementOfAccount::create(
            self::account(),
            new \DateTimeImmutable('2026-04-20'),
            new \DateTimeImmutable('2026-07-19'),
            true
        );

        $bpd = self::createBpd(
            self::createHikazsSegment(7, true),
            self::createHispasSegment(true)
        );
        $requestSegments = $action->getNextRequest($bpd, null);

        self::assertCount(1, $requestSegments);
        /** @var HKKAZv7 $request */
        $request = $requestSegments[0];
        self::assertInstanceOf(HKKAZv7::class, $request);
        self::assertTrue($request->alleKonten);
        self::assertSame('20260420', $request->vonDatum);
        self::assertSame('20260719', $request->bisDatum);
        self::assertSame('DE44500105175407324931', $request->kontoverbindungInternational->iban);
        self::assertSame('INGDDEFFXXX', $request->kontoverbindungInternational->bic);
        self::assertSame('5407324931', $request->kontoverbindungInternational->kontonummer);
    }

    public function testCreateRequestRejectsAllAccountsWhenNotSupportedByBank()
    {
        $action = GetStatementOfAccount::create(self::account(), null, null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allAccounts=true');
        $action->getNextRequest(self::createBpd(self::createHikazsSegment(6, false)), null);
    }

    private static function account(): SEPAAccount
    {
        return (new SEPAAccount())
            ->setIban('DE44500105175407324931')
            ->setBic('INGDDEFFXXX')
            ->setAccountNumber('5407324931')
            ->setBlz('50010517');
    }

    private static function parameterKontoumsaetze(bool $allAccountsAllowed): ParameterKontoumsaetzeV2
    {
        $parameter = new ParameterKontoumsaetzeV2();
        $parameter->speicherzeitraum = 90;
        $parameter->eingabeAnzahlEintraegeErlaubt = false;
        $parameter->alleKontenErlaubt = $allAccountsAllowed;
        return $parameter;
    }

    private static function createHikazsSegment(int $version, bool $allAccountsAllowed): HIKAZSv5|HIKAZSv6|HIKAZSv7
    {
        switch ($version) {
            case 5:
                $segment = HIKAZSv5::createEmpty();
                break;
            case 6:
                $segment = HIKAZSv6::createEmpty();
                break;
            case 7:
                $segment = HIKAZSv7::createEmpty();
                break;
            default:
                throw new \InvalidArgumentException("Unsupported test HIKAZS version $version");
        }

        $segment->maximaleAnzahlAuftraege = 1;
        $segment->anzahlSignaturenMindestens = 1;
        if (!$segment instanceof HIKAZSv5) {
            $segment->sicherheitsklasse = 0;
        }
        $segment->parameter = self::parameterKontoumsaetze($allAccountsAllowed);
        return $segment;
    }

    private static function createHispasSegment(bool $nationalAccountAllowed): HISPASv1
    {
        $parameter = new ParameterSepaKontoverbindungAnfordernV1();
        $parameter->einzelkontenabrufErlaubt = true;
        $parameter->nationaleKontoverbindungErlaubt = $nationalAccountAllowed;
        $parameter->strukturierterVerwendungszweckErlaubt = false;
        $parameter->unterstuetzteSepaDatenformate = ['urn:iso:std:iso:20022:tech:xsd:pain.001.001.03'];

        $segment = HISPASv1::createEmpty();
        $segment->maximaleAnzahlAuftraege = 1;
        $segment->anzahlSignaturenMindestens = 1;
        $segment->sicherheitsklasse = 0;
        $segment->parameter = $parameter;
        return $segment;
    }

    private static function createBpd(BaseSegment $hikazs, ?BaseSegment $hispas = null): BPD
    {
        $bpd = new class extends BPD {
            public function getBankName()
            {
                return 'Testbank';
            }
        };

        $bpd->parameters['HIKAZS'][$hikazs->getVersion()] = $hikazs;
        if ($hispas !== null) {
            $bpd->parameters['HISPAS'][$hispas->getVersion()] = $hispas;
        }

        return $bpd;
    }
}
