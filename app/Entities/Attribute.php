<?php
/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 */

declare(strict_types=1);

namespace Pim\Entities;

use Espo\Core\Templates\Entities\Base;
use Espo\Core\Utils\Json;

class Attribute extends Base
{
    protected const DATA_FIELD = 'field';

    protected $entityType = "Attribute";

    public function _setMeasure($value)
    {
        $this->setDataField('measure', $value);

        // for backward compatibility
        $this->set('typeValue', [$value]);
    }

    public function _getMeasure()
    {
        return $this->getDataField('measure');
    }

    public function _setUnitDefault($value)
    {
        $this->setDataField('unitDefault', $value);
    }

    public function _getUnitDefault()
    {
        return $this->getDataField('unitDefault');
    }

    public function _setUnitDefaultUnit($value)
    {
        $this->setDataField('unitDefaultUnit', $value);
    }

    public function _getUnitDefaultUnit()
    {
        return $this->getDataField('unitDefaultUnit');
    }

    public function setData(array $data): void
    {
        $this->set('data', $data);
    }

    public function setDataField(string $key, $value): void
    {
        $data = $this->getData();
        $data[self::DATA_FIELD][$key] = $value;

        $this->set('data', $data);
        $this->valuesContainer[$key] = $value;
    }

    public function getDataField(string $key)
    {
        $data = $this->getDataFields();

        return isset($data[$key]) ? $data[$key] : null;
    }

    public function getDataFields()
    {
        $data = $this->getData();

        $result = isset($data[self::DATA_FIELD]) ? $data[self::DATA_FIELD] : [];

        // for backward compatibility
        if ($this->get('type') === 'unit' && empty($result['measure']) && !empty($this->get('typeValue')) && isset($this->get('typeValue')[0])) {
            $result['measure'] = $this->get('typeValue')[0];
        }

        return $result;
    }

    public function getData(): array
    {
        $data = $this->get('data');

        return empty($data) ? [] : Json::decode(Json::encode($data), true);
    }
}
