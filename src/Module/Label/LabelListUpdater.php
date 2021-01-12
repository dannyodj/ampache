<?php
/*
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright 2001 - 2020 Ampache.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Ampache\Module\Label;

use Ampache\Model\Label;
use Ampache\Model\ModelFactoryInterface;
use Ampache\Repository\LabelRepositoryInterface;

final class LabelListUpdater implements LabelListUpdaterInterface
{
    private LabelRepositoryInterface $labelRepository;

    private ModelFactoryInterface $modelFactory;

    public function __construct(
        LabelRepositoryInterface $labelRepository,
        ModelFactoryInterface $modelFactory
    ) {
        $this->labelRepository = $labelRepository;
        $this->modelFactory    = $modelFactory;
    }

    /**
     * Update the labels list based on commated list (ex. label1,label2,label3,..)
     */
    public function update(
        string $labelsComma,
        int $artistId,
        bool $overwrite
    ): bool {
        debug_event('label.class', 'Updating labels for values {' . $labelsComma . '} artist {' . $artistId . '}', 5);

        $clabels      = $this->labelRepository->getByArtist((int) $artistId);
        $filter_list  = preg_split('/(\s*,*\s*)*,+(\s*,*\s*)*/', $labelsComma);
        $editedLabels = (is_array($filter_list)) ? array_unique($filter_list) : array();

        foreach ($clabels as $clid => $clv) {
            if ($clid) {
                $clabel = new Label($clid);
                debug_event('label.class', 'Processing label {' . $clabel->name . '}...', 5);
                $found   = false;
                $lstring = '';

                foreach ($editedLabels as $key => $value) {
                    if ($clabel->name == $value) {
                        $found   = true;
                        $lstring = $key;
                        break;
                    }
                }

                if ($found) {
                    debug_event('label.class', 'Already found. Do nothing.', 5);
                    unset($editedLabels[$lstring]);
                } elseif ($overwrite) {
                    debug_event('label.class', 'Not found in the new list. Delete it.', 5);
                    $this->labelRepository->removeArtistAssoc($artistId);
                }
            }
        }

        // Look if we need to add some new labels
        foreach ($editedLabels as $key => $value) {
            if ($value != '') {
                debug_event('label.class', 'Adding new label {' . $value . '}', 4);
                $label_id = $this->labelRepository->lookup($value);
                if ($label_id === 0) {
                    debug_event('label.class', 'Creating a label directly from artist editing is not allowed.', 3);
                }
                if ($label_id > 0) {
                    $clabel = new Label($label_id);
                    $this->labelRepository->addArtistAssoc($clabel->id, $artistId);
                }
            }
        }

        return true;
    }
}