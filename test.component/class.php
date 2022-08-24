<?

use Bitrix\Main\Loader,
    Bitrix\Main\Localization\Loc,
    Bitrix\Main\Context;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

if (!Loader::includeModule('iblock')) {
    ShowError(Loc::getMessage('IBLOCK_MODULE_NOT_INSTALLED'));
    return;
}

global $USER;

class TestComponent extends CBitrixComponent
{
    public function __construct($component = null)
	{
		parent::__construct($component);
		$this->setExtendedMode(false);
	}

    public function onPrepareComponentParams($params)
	{
		$params = parent::onPrepareComponentParams($params);
		return $params;
	}

    // список доступных классов
    protected function getClassList(){

        $arUser = $USER->GetByID(intval($USER->GetID()))->Fetch();
        $pid = $arUser["UF_POSITION_ID"];// свойство пользователя ID должности
        $dbPosition  = CIBlockElement::GetList(
            array(),
            array(
                "IBLOCK_ID" => $this->arParams['POSITION_IBLOCK_ID'], // ИБ со списком должностей
                "ACTIVE"    => "Y",
                "ID"        =>  $pid
            ),
            false,
            false,
            [
                "ID",
                "IBLOCK_ID"
            ]
        );

        $this->arResult['AVAILABLE_CLASS'] = [];
        if ($arPosition = $dbPosition->GetNext()) {
            foreach($arPosition->GetProperties() as $prop){
                if($prop['NAME'] === "CLASS"){ // множественнок свойсво со списком доступных классов
                    array_push($this->arResult['AVAILABLE_CLASS'], $prop['VALUE']);
                }
            }
        }
        return $this->arResult['AVAILABLE_CLASS'];
    }

    // список всех автомобилей, доступных пользователю
    protected function getAutoList(){
        $classList = $this->getClassList();
        $dbAuto  = CIBlockElement::GetList(
            array(),
            array(
                "IBLOCK_ID" => $this->arParams['AUTO_IBLOCK_ID'], // ИБ со списком автомобилей
                "ACTIVE"    => "Y",
                "PROPERTY_CALSS_ID_VALUE" =>  $classList
            ),
            false,
            false,
            [
                "ID",
                "IBLOCK_ID",
                "NAME",
                "PROPERTY_DRIVER_ID", //id водителя
                "PROPERTY_CALSS_ID"
            ]
        );

        $this->arResult['ALL_AVAILABLE_AUTO'] = [];
        while ($arAuto = $dbAuto->GetNext()) {
            $this->arResult['ALL_AVAILABLE_AUTO'][$arAuto['ID']] = [
                'MODEL' => $arAuto['NAME'],
                'DRIVER_ID' => $arAuto['PROPERTY_DRIVER_ID_VALUE'],
                'CLASS_ID' => $arAuto['PROPERTY_CLASS_ID_VALUE']
            ];
        }
        return $this->arResult['ALL_AVAILABLE_AUTO'];
    }

    // получение списка доступных автомобилей на заданное время
    protected function getAvailableAutoOnTime(){
        $request = Context::getCurrent()->getRequest();
        $timeFrom = $request->getQuery("TIME_FROM"); // get параметр время от
        $timeTo = $request->getQuery("TIME_TO"); // get параметр время до
        if(empty($timeFrom) || empty($timeTo)) return false;

        $this->getAutoList();

        $dbRent  = CIBlockElement::GetList(
            array(),
            array(
                "IBLOCK_ID" => $this->arParams['RENT_IBLOCK_ID'], // ИБ со списком поездок
                "ACTIVE"    => "Y",
                "AUTO_ID" =>  array_keys($this->arRusult['ALL_AVAILABLE_AUTO']),
                [
                    "LOGIC" => "OR",
                    [
                        "LOGIC" => "AND",
                        "<=PROPERTY_TIME_FROM_VALUE" => $timeFrom,
                        ">=PROPERTY_TIME_TO_VALUE" => $timeTo,
                    ],
                    [
                        "LOGIC" => "AND",
                        "<=PROPERTY_TIME_TO_VALUE" => $timeFrom,
                        ">=PROPERTY_TIME_TO_VALUE" => $timeTo,
                    ],
                    [
                        "LOGIC" => "AND",
                        "<=PROPERTY_TIME_FROM_VALUE" => $timeFrom,
                        ">=PROPERTY_TIME_FROM_VALUE" => $timeTo,
                    ],
                ]
            ),
            false,
            false,
            [
                "ID",
                "IBLOCK_ID",
                "PROPERTY_AUTO_ID", // id автомобиля
                "PROPERTY_TIME_FROM", // начало
                "PROPERTY_TIME_TO"    // окончание поездки
            ]
        );

        $unavailableAutoIdList = [];
        while ($arRent = $dbRent->GetNext()) {
            array_push($unavailableAutoIdList, $arRent["PROPERTY_AUTO_ID_VALUE"]);
        }
        $this->arResult['AVAILABLE_AUTO'] = array_diff_key($this->arResult['ALL_AVAILABLE_AUTO'], $unavailableAutoIdList);
        unset($unavailableAutoIdList);

        $this->fillClasseNames();
        $this->fillDriversNames();


        return $this->arResult['AVAILABLE_AUTO'];
    }

    private function fillClasseNames(){
        $dbClasses  = CIBlockElement::GetList(
            array(),
            array(
                "IBLOCK_ID" => $this->arParams['CLASS_IBLOCK_ID'], // ИБ со списком классов
                "ACTIVE"    => "Y",
                "ID" =>  array_column($this->arResult['AVAILABLE_AUTO'], 'CLASS_ID')
            ),
            false,
            false,
            [
                "ID",
                "IBLOCK_ID",
                "NAME"
            ]
        );
        $classesList = [];
        while ($arClass = $dbClasses->GetNext()) {
            $classesList[$arClass['ID']] = $arClass['NAME'];
        }
        foreach($this->arResult['AVAILABLE_AUTO'] as &$item){
            $item['CLASS_NAME'] = $classesList[$item['CLASS_ID']];
        }
        return $this->arResult['AVAILABLE_AUTO'];
    }

    private function fillDriversNames(){
        $idsFilterStr = "";
        foreach(array_column($this->arResult['AVAILABLE_AUTO'], 'DRIVER_ID') as $id){
            $idsFilterStr .= $id . ' | ';
        }
        $idsFilterStr = substr(0, -3);

        $filter = ["ID" => $idsFilterStr];
        $dbUsers = CUser::GetList(($by="id"), ($order="desc"), $filter);
        $usersList = [];
        while($arUser = $dbUsers->Fetch()){
            $usersList[$arUser["ID"]] = $arUser['SECOND_NAME'].' '.$arUser['NAME'].' '.$arUser['LAST_NAME'];
        }
        foreach($this->arResult['AVAILABLE_AUTO'] as &$item){
            $item['DRIVER_NAME'] = $usersList[$item['DRIVER_ID']];
        }
        return $this->arResult['AVAILABLE_AUTO'];
    }


    public function executeComponent()
    {
        if ($this->startResultCache()) {
            $this->getAvailableAutoOnTime();
        }
        $this->includeComponentTemplate();
    }
}
