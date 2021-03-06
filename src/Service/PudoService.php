<?php

namespace Invertus\dpdBaltics\Service;

use Carrier;
use Configuration;
use Country;
use DPDBaltics;
use DPDProduct;
use DPDPudo;
use DPDShop;
use Invertus\dpdBaltics\Config\Config;
use Invertus\dpdBaltics\Factory\ShopFactory;
use Invertus\dpdBaltics\Repository\ParcelShopRepository;
use Invertus\dpdBaltics\Repository\PudoRepository;
use Invertus\dpdBaltics\Service\API\ParcelShopSearchApiService;
use Invertus\dpdBaltics\Service\Parcel\ParcelShopService;
use Invertus\dpdBalticsApi\Api\DTO\Object\OpeningHours;
use Invertus\dpdBalticsApi\Api\DTO\Object\ParcelShop;
use Language;
use Smarty;
use Tools;
use Cart;

class PudoService
{
    /** @var  array */
    public $messages = [];
    /**
     * @var PudoRepository
     */
    private $pudoRepository;
    /**
     * @var Smarty
     */
    private $smarty;
    /**
     * @var DPDBaltics
     */
    private $module;
    /**
     * @var ParcelShopSearchApiService
     */
    private $searchApiService;
    /**
     * @var GoogleApiService
     */
    private $googleApiService;
    /**
     * @var Language
     */
    private $language;
    /**
     * @var ParcelShopRepository
     */
    private $parcelShopRepository;
    /**
     * @var ShopFactory
     */
    private $shopFactory;

    public function __construct(
        PudoRepository $pudoRepository,
        Smarty $smarty,
        DPDBaltics $module,
        ParcelShopSearchApiService $searchApiService,
        Language $language,
        GoogleApiService $googleApiService,
        ParcelShopRepository $parcelShopRepository,
        ShopFactory $shopFactory
    ) {
        $this->pudoRepository = $pudoRepository;
        $this->smarty = $smarty;
        $this->module = $module;
        $this->searchApiService = $searchApiService;
        $this->googleApiService = $googleApiService;
        $this->language = $language;
        $this->parcelShopRepository = $parcelShopRepository;
        $this->shopFactory = $shopFactory;
    }

    /**
     * @param ParcelShop[] $pudoServices
     * @return array|ParcelShop[]
     */
    public function setPudoServiceTypes(array $pudoServices)
    {
        foreach ($pudoServices as &$pudoService) {
            $pudoService->type = $this->getPudoServiceType($pudoService->getParcelShopId());
        }
        return $pudoServices;
    }

    public function getPudoServiceType($pudoId)
    {
        if (Tools::substr($pudoId, 2, 2) == '90') {
            return 'locker';
        }
        return 'parcel_shop';
    }

    /**
     * @param ParcelShop[] $pudoServices
     * @return array|ParcelShop[]
     */
    public function formatPudoServicesWorkHours(array $pudoServices)
    {
        /** @var ParcelShop $pudoService */
        foreach ($pudoServices as $key => $pudoService) {
            $pudoServices[$key]->setOpeningHours($this->formatPudoServiceWorkHours($pudoService->getOpeningHours()));
        }

        return $pudoServices;
    }

    public function formatPudoServiceWorkHours(array $openingHours)
    {
        /**
         * @var  $key
         * @var OpeningHours $openingHour
         */
        foreach ($openingHours as $key => $openingHour) {
            $openingHour->setWorkHoursFormatted($this->formatWorkHours($openingHour));
            $openingHours[$key] = $openingHour;
        }

        return $openingHours;
    }

    public function formatWorkHours(OpeningHours $openingHour)
    {
        if ($openingHour->getCloseMorning() === $openingHour->getOpenAfternoon()) {
            return "{$openingHour->getOpenMorning()}-{$openingHour->getCloseAfternoon()}";
        }

        return "{$openingHour->getOpenMorning()}-{$openingHour->getCloseMorning()} {$openingHour->getOpenAfternoon()}-{$openingHour->getCloseAfternoon()}";
    }

    public function savePudoCartOrder($idCarrier, $idCart, $idPudo, $countryCode)
    {
        /** @var DPDShop $pudoShop */
        $pudoShop = DPDShop::getShopByPudoId($idPudo);
        $carrier = new Carrier($idCarrier);

        $id = $this->pudoRepository->getIdByCart($idCart);

        $pudo = new DPDPudo($id);
        $pudo->id_cart = (int)$idCart;
        $pudo->pudo_id = $idPudo;
        $pudo->id_carrier = $carrier->id;
        $pudo->country_code = $countryCode;
        $pudo->city = $pudoShop->city;
        $pudo->street = $pudoShop->street;
        $pudo->post_code = $pudoShop->p_code;

        if (!$pudo->save()) {
            return false;
        }

        return true;
    }

    public function searchPudoServices($city, $carrierId, $cartId)
    {
        $cart = null;
        if ($cartId) {
            $cart = new Cart($cartId);
        }

        /** @var \Invertus\dpdBaltics\Provider\CurrentCountryProvider $currentCountryProvider */
        $currentCountryProvider = $this->module->getContainer(\Invertus\dpdBaltics\Provider\CurrentCountryProvider::class);
        $countryCode = $currentCountryProvider->getCurrentCountryIsoCode($cart);

        /** @var ParcelShopService $parcelShopService */
        /** @var PudoService $pudoService */
        $parcelShopService= $this->module->getContainer(ParcelShopService::class);
        $pudoService = $this->module->getContainer(PudoService::class);

        /** @var ParcelShop[] $parcelShops */
        $parcelShops = $parcelShopService->getParcelShopsByCountryAndCity($countryCode, $city);

        $pudoServices = $pudoService->setPudoServiceTypes($parcelShops);
        $pudoServices = $pudoService->formatPudoServicesWorkHours($pudoServices);

        /** @var PudoRepository $pudoRepo */
        $pudoRepo = $this->module->getContainer(PudoRepository::class);
        $pudoId = $pudoRepo->getIdByCart($cartId);
        $selectedPudo = new DPDPudo($pudoId);

        $this->smarty->assign(
            [
                'carrierId' => $carrierId,
                'pickUpMap' => Configuration::get(Config::PICKUP_MAP),
                'pudoId' => 1,
                'pudoServices' => $pudoServices,
                'dpd_pickup_logo' => $this->module->getPathUri() . 'views/img/pickup.png',
                'dpd_locker_logo' => $this->module->getPathUri() . 'views/img/locker.png',
                'countryList' => Country::getCountries($this->language->id, true),
                'selectedPudo' => $selectedPudo,
                'saved_pudo_id' => $selectedPudo->pudo_id
            ]
        );

        $coordinates = [];

        if (isset($parcelShops[0])) {
            $coordinates = [
                'lat' => $parcelShops[0]->getLatitude(),
                'lng' => $parcelShops[0]->getLongitude(),
            ];
        }

        return [
            'template' => $this->smarty->fetch(
                $this->module->getLocalPath() . '/views/templates/hook/front/partials/markers-list.tpl'
            ),
            'status' => true,
            'coordinates' => $coordinates
        ];
    }

    public function savePudoOrder($productId, $pudoId, $isoCode, $cartId, $city, $street)
    {
        $pudoOrderId = $this->pudoRepository->getIdByCart($cartId);
        $product = new DPDProduct($productId);
        $carrier = Carrier::getCarrierByReference($product->id_reference);
        $countryCode = $isoCode;
        $pudoOrder = new DPDPudo($pudoOrderId);
        $pudoOrder->pudo_id = $pudoId;
        $pudoOrder->id_carrier = $carrier->id;
        $pudoOrder->country_code = $countryCode;
        $pudoOrder->id_cart = $cartId;
        $pudoOrder->city = $city;
        $pudoOrder->street = $street;
        $pudoOrder->save();
    }

    public function saveSelectedParcel($cartId, $city, $street, $countryCode, $idCarrier)
    {
        /** @var DPDShop $parcelShopId */
        $parcelShopId = $this->parcelShopRepository->getIdByCityAndStreet($city, $street);
        if (!$parcelShopId) {
            return true;
        }
        $parcelShop = DPDShop::getShopByPudoId($parcelShopId);
        $carrier = new Carrier($idCarrier);

        $pudoId = $this->pudoRepository->getIdByCart($cartId);

        $pudo = new DPDPudo($pudoId);

        $pudo->id_cart = (int)$cartId;
        $pudo->pudo_id = $parcelShop->parcel_shop_id;
        $pudo->id_carrier = $carrier->id;
        $pudo->country_code = $countryCode;
        $pudo->city = $parcelShop->city;
        $pudo->street = $parcelShop->street;
        $pudo->post_code = $parcelShop->p_code;

        return $pudo->save();
    }

    public function getClosestParcelShops($pudoId)
    {
        /** @var DPDShop $pudo */
        $pudo = DPDShop::getShopByPudoId($pudoId);

        $parcelShops = $this->parcelShopRepository->getClosestPudoShops(
            $pudo->longitude,
            $pudo->latitude,
            Config::PARCEL_SHOP_MAP_DISTANCE,
            Config::PARCEL_SHOP_MAP_POINTS_LIMIT
        );

        return $this->shopFactory->createShop($parcelShops);
    }

    public function getPudoIdByCityAndAddress($city, $street)
    {
        return $this->parcelShopRepository->getIdByCityAndStreet($city, $street);
    }
}
