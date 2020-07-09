<?php

namespace IntVent\EBoekhouden;

use DateTime;
use IntVent\EBoekhouden\Exceptions\EboekhoudenSoapException;
use IntVent\EBoekhouden\Models\EboekhoudenInvoice;
use IntVent\EBoekhouden\Models\EboekhoudenInvoiceList;
use IntVent\EBoekhouden\Models\EboekhoudenLedger;
use IntVent\EBoekhouden\Models\EboekhoudenMutation;
use IntVent\EBoekhouden\Models\EboekhoudenRelation;
use IntVent\EBoekhouden\Models\InvoiceFilter;
use IntVent\EBoekhouden\Models\MutationFilter;
use SoapClient;
use SoapFault;

class Client
{
    protected SoapClient $soapClient;
    protected ?string $sessionId = null;
    protected string $username;
    protected string $secCode1;
    protected string $secCode2;
    protected string $wsdl = 'https://soap.e-boekhouden.nl/soap.asmx?wsdl';

    /**
     * Client constructor.
     *
     * @param  string  $username
     * @param  string  $secCode1
     * @param  string  $secCode2
     * @throws EboekhoudenSoapException
     */
    public function __construct(string $username, string $secCode1, string $secCode2)
    {
        $this->username = $username;
        $this->secCode1 = $secCode1;
        $this->secCode2 = $secCode2;

        $this->createSoapClient();
    }

    /**
     * Create SoapClient to connect to E-Boekhouden.nl.
     *
     * @throws EboekhoudenSoapException
     */
    protected function createSoapClient(): void
    {
        if (! empty($this->soapClient) && ! empty($this->sessionId)) {
            return;
        }

        try {
            $this->soapClient = new SoapClient($this->wsdl);
        } catch (SoapFault $exception) {
            throw new EboekhoudenSoapException($exception->getMessage());
        }

        $result = $this->soapClient->__soapCall('OpenSession', [
            'OpenSession' => [
                'Username' => $this->username,
                'SecurityCode1' => $this->secCode1,
                'SecurityCode2' => $this->secCode2,
            ],
        ]);

        $this->checkError('OpenSession', $result);

        $this->sessionId = $result->OpenSessionResult->SessionID;
    }

    /**
     * Check E-Boekhouden.nl response for errors.
     *
     * @param  string  $methodName
     * @param  object  $response
     * @throws EboekhoudenSoapException
     */
    private function checkError(string $methodName, object $response): void
    {
        if (! empty($response->{$methodName.'Result'}->ErrorMsg->LastErrorCode)) {
            throw new EboekhoudenSoapException($response->{$methodName.'Result'}->ErrorMsg->LastErrorDescription);
        }
    }

    /**
     * Get all relations from E-Boekhouden.nl.
     *
     * @param  string  $keyword
     * @param  string  $code
     * @param  int  $id
     * @return EboekhoudenRelation[]
     * @throws EboekhoudenSoapException
     */
    public function getRelations($keyword = '', $code = '', $id = 0): array
    {
        $result = $this->soapClient->__soapCall('GetRelaties', [
            'GetRelaties' => [
                'SessionID' => $this->sessionId,
                'SecurityCode2' => $this->secCode2,
                'cFilter' => [
                    'Trefwoord' => $keyword,
                    'Code' => $code,
                    'ID' => $id,
                ],
            ],
        ]);

        $this->checkError('GetRelaties', $result);

        $relations = $result->GetRelatiesResult->Relaties->cRelatie;

        if (! is_array($relations)) {
            $relations = [$relations];
        }

        return array_map(fn ($item) => (new EboekhoudenRelation((array) $item))->toArray(), $relations);
    }

    /**
     * Get all ledgers from E-Boekhouden.nl.
     *
     * @param  string  $id
     * @param  string  $code
     * @param  string  $category
     * @return EboekhoudenLedger[]
     * @throws EboekhoudenSoapException
     */
    public function getLedgers(string $id = '', string $code = '', string $category = ''): array
    {
        $result = $this->soapClient->__soapCall('GetGrootboekrekeningen', [
            "GetGrootboekrekeningen" => [
                "SessionID" => $this->sessionId,
                "SecurityCode2" => $this->secCode2,
                "cFilter" => [
                    "ID" => $id,
                    "Code" => $code,
                    "Categorie" => $category,
                ],
            ],
        ]);

        $this->checkError('GetGrootboekrekeningenResult', $result);

        $ledgers = $result->GetGrootboekrekeningenResult->Rekeningen->cGrootboekrekening;

        if (! is_array($ledgers)) {
            $ledgers = [$ledgers];
        }

        return array_map(fn ($item) => (new EboekhoudenLedger((array) $item))->toArray(), $ledgers);
    }

    /**
     * Get all invoices from E-Boekhouden.nl.
     *
     * @param  InvoiceFilter|null  $filter
     * @return EboekhoudenInvoiceList[]
     * @throws EboekhoudenSoapException
     */
    public function getInvoices(InvoiceFilter $filter = null): array
    {
        if (is_null($filter)) {
            $filter = new InvoiceFilter();
        }

        $dateFrom = $filter->getDateFrom() ?? new DateTime('1970-01-01 00:00:00');
        $dateTo = $filter->getDateTo() ?? new DateTime('2050-12-31 23:59:59');

        $result = $this->soapClient->__soapCall('GetFacturen', [
            'GetFacturen' => [
                'SessionID' => $this->sessionId,
                'SecurityCode2' => $this->secCode2,
                'cFilter' => [
                    'Factuurnummer' => $filter->getInvoiceNumber(),
                    'Relatiecode' => $filter->getRelationCode(),
                    'DatumVan' => $dateFrom->format('c'),
                    'DatumTm' => $dateTo->format('c'),
                ],
            ],
        ]);

        $this->checkError('GetFacturen', $result);

        if (! isset($result->GetFacturenResult->Facturen->cFactuurList)) {
            return [];
        }

        $invoices = $result->GetFacturenResult->Facturen->cFactuurList;

        if (! is_array($invoices)) {
            $invoices = [$invoices];
        }

        return array_map(fn ($item) => (new EboekhoudenInvoiceList((array) $item))->toArray(), $invoices);
    }

    /**
     * Get all mutations from E-Boekhouden.nl.
     *
     * @param  MutationFilter|null  $filter
     * @return EboekhoudenMutation[]
     * @throws EboekhoudenSoapException
     */
    public function getMutations(MutationFilter $filter = null): array
    {
        if (is_null($filter)) {
            $filter = new MutationFilter();
        }

        $dateFrom = $filter->getDateFrom() ?? new DateTime('1970-01-01 00:00:00');
        $dateTo = $filter->getDateTo() ?? new DateTime('2050-12-31 23:59:59');

        $result = $this->soapClient->__soapCall('GetMutaties', [
            'GetMutaties' => [
                'SessionID' => $this->sessionId,
                'SecurityCode2' => $this->secCode2,
                'cFilter' => [
                    'MutatieNr' => $filter->getMutationNumber(),
                    'MutatieNrVan' => 0,
                    'MutatieNrTm' => 0,
                    'Factuurnummer' => '',
                    'DatumVan' => $dateFrom->format('c'),
                    'DatumTm' => $dateTo->format('c'),
                ],
            ],
        ]);

        $this->checkError('GetMutaties', $result);

        if (! isset($result->GetMutatiesResult->Mutaties->cMutatieList)) {
            return [];
        }

        $mutations = $result->GetMutatiesResult->Mutaties->cMutatieList;

        if (! is_array($mutations)) {
            $mutations = [$mutations];
        }

        return array_map(fn ($item) => new EboekhoudenMutation((array) $item), $mutations);
    }

    /**
     * Add a new invoice to E-boekhouden.nl
     *
     * @param  EboekhoudenInvoice  $invoice
     * @return string       New invoice number
     * @throws EboekhoudenSoapException
     */
    public function addInvoice(EboekhoudenInvoice $invoice): string
    {
        $result = $this->soapClient->__soapCall('AddFactuur', [
            "AddFactuur" => [
                "SessionID" => $this->sessionId,
                "SecurityCode2" => $this->secCode2,
                "oFact" => $this->getOFact($invoice),
            ],
        ]);

        $this->checkError('AddFactuur', $result);

        return (string) $result->AddFactuurResult->Factuurnummer;
    }

    /**
     * Add new relation to E-Boekhouden.nl.
     *
     * @param  EboekhoudenRelation  $relation
     * @return EboekhoudenRelation
     * @throws EboekhoudenSoapException|Exceptions\EboekhoudenException
     */
    public function addRelation(EboekhoudenRelation $relation): EboekhoudenRelation
    {
        $result = $this->soapClient->__soapCall('AddRelatie', [
            "AddRelatie" => [
                "SessionID" => $this->sessionId,
                "SecurityCode2" => $this->secCode2,
                "oRel" => $this->getORel($relation),
            ],
        ]);

        $this->checkError('AddRelatie', $result);

        $relation->setId((int) $result->AddRelatieResult->Rel_ID);

        return $relation;
    }

    /**
     * Update relation
     *
     * @param  EboekhoudenRelation  $relation
     * @return EboekhoudenRelation
     * @throws EboekhoudenSoapException
     */
    public function updateRelation(EboekhoudenRelation $relation): EboekhoudenRelation
    {
        $result = $this->soapClient->__soapCall('UpdateRelatie', [
            "UpdateRelatie" => [
                "SessionID" => $this->sessionId,
                "SecurityCode2" => $this->secCode2,
                "oRel" => $this->getORel($relation),
            ],
        ]);
        $this->checkError('UpdateRelatie', $result);

        return $relation;
    }

    /**
     * @param  EboekhoudenInvoice  $invoice
     * @return array
     */
    private function getOFact(EboekhoudenInvoice $invoice): array
    {
        $lines = array_map(fn ($line) => [
            'Aantal' => $line->getAmount(),
            'Eenheid' => $line->getUnit(),
            'Code' => $line->getCode(),
            'Omschrijving' => $line->getDescription(),
            'PrijsPerEenheid' => $line->getPrice(),
            'BTWCode' => $line->getTaxCode(),
            'TegenrekeningCode' => $line->getLedgerCode(),
            'KostenplaatsID' => 0,
        ], $invoice->getLines());

        return [
            "Factuurnummer" => $invoice->getInvoiceNumber(),
            "Relatiecode" => $invoice->getRelationCode(),
            "Datum" => (new DateTime())->format('c'),
            "Betalingstermijn" => $this->config['payment_term'],
            "Factuursjabloon" => $this->config['invoice_template'],
            "PerEmailVerzenden" => 0,
            "EmailOnderwerp" => "",
            "EmailBericht" => "",
            "EmailVanAdres" => $this->config['email_from_address'],
            "EmailVanNaam" => $this->config['email_from_name'],
            "AutomatischeIncasso" => 0,
            "IncassoIBAN" => "",
            "IncassoMachtigingSoort" => "",
            "IncassoMachtigingID" => "",
            "IncassoMachtigingDatumOndertekening" => (new DateTime("1970-01-01 00:00:00"))->format('c'),
            "IncassoMachtigingFirst" => 0,
            "IncassoRekeningNummer" => "",
            "IncassoTnv" => "",
            "IncassoPlaats" => "",
            "IncassoOmschrijvingRegel1" => "",
            "IncassoOmschrijvingRegel2" => "",
            "IncassoOmschrijvingRegel3" => "",
            "InBoekhoudingPlaatsen" => 1,
            "BoekhoudmutatieOmschrijving" => $invoice->getDescription(),
            "Regels" => $lines,
        ];
    }

    /**
     * @param  EboekhoudenRelation  $relation
     * @return array
     */
    private function getORel(EboekhoudenRelation $relation): array
    {
        $id = $relation->getId();

        if (empty($id) || $id == 1) {
            $id = 0;
        }

        return [
            "ID" => $id,
            "AddDatum" => ($relation->getAddDate() ?? new DateTime())->format('c'),
            "Code" => (string) $relation->getCode() ?? '',
            "Bedrijf" => $relation->getCompany() ?? '',
            "Contactpersoon" => $relation->getContact() ?? '',
            "Geslacht" => $relation->getGender() ?? '',
            "Adres" => $relation->getAddress() ?? '',
            "Postcode" => $relation->getZipcode() ?? '',
            "Plaats" => $relation->getCity() ?? '',
            "Land" => $relation->getCountry() ?? '',
            "Adres2" => "",
            "Postcode2" => "",
            "Plaats2" => "",
            "Land2" => "",
            "Telefoon" => $relation->getPhone() ?? '',
            "GSM" => $relation->getCellPhone() ?? '',
            "FAX" => "",
            "Email" => $relation->getEmail() ?? '',
            "Site" => $relation->getSite() ?? '',
            "Notitie" => $relation->getNotes() ?? '',
            "Bankrekening" => "",
            "Girorekening" => "",
            "BTWNummer" => $relation->getVatNumber() ?? '',
            "Aanhef" => "",
            "IBAN" => "",
            "BIC" => "",
            "BP" => "",
            "Def1" => "",
            "Def2" => "",
            "Def3" => "",
            "Def4" => "",
            "Def5" => "",
            "Def6" => "",
            "Def7" => "",
            "Def8" => "",
            "Def9" => "",
            "Def10" => "",
            "LA" => "",
            "Gb_ID" => 0,
            "GeenEmail" => 0,
            "NieuwsbriefgroepenCount" => 0,
        ];
    }
}
