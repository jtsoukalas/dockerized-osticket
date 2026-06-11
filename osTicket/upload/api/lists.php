<?php
require 'api.inc.php';
require_once INCLUDE_DIR . 'class.list.php';
require_once INCLUDE_DIR . 'class.api.php';

class ListsApiController extends ApiController {
    private function getListIdFromHeader() {
        if (!empty($_SERVER['HTTP_X_LIST_ID']))
            return (int) $_SERVER['HTTP_X_LIST_ID'];
        if (!empty($_SERVER['HTTP_LIST_ID']))
            return (int) $_SERVER['HTTP_LIST_ID'];
        return 0;
    }

    private function lookupList($data, $pathListId=0) {
        if ($pathListId)
            return DynamicList::lookup((int) $pathListId);

        $headerListId = $this->getListIdFromHeader();
        if ($headerListId)
            return DynamicList::lookup($headerListId);

        if (!empty($data['list_id']))
            return DynamicList::lookup((int) $data['list_id']);

        $name = isset($data['list_name']) ? trim($data['list_name']) : 'Projects';
        if ($name == '')
            return null;

        return DynamicList::objects()->filter(array('name' => $name))->first();
    }

    private function normalizeValue($raw) {
        // Case 1: Simple array/string values [1, 2, 3]
        if (!is_array($raw) && !is_object($raw)) {
            return array(
                'value' => (string) $raw,
                'extra' => null,
                'properties' => array(),
            );
        }

        // Case 2: Object-based values {"project_id": 1, "head_email": "Mara"}
        $raw = (array) $raw;
        $projectId = isset($raw['project_id']) ? (string)$raw['project_id'] : (isset($raw['value']) ? (string)$raw['value'] : '');
        
        // Extract properties: exclude project_id/value to get only custom fields
        $properties = array_filter($raw, function($key) {
            return !in_array($key, ['project_id', 'value', 'extra']);
        }, ARRAY_FILTER_USE_KEY);

        return array(
            'value'      => $projectId,
            'extra'      => $projectId,
            'properties' => $properties,
        );
    }

    function getList($format='json') {
        try {
            if (!($key = $this->requireApiKey()))
                return $this->exerr(401, 'API key not authorized');

            $lists = DynamicList::objects()->order_by('name');
            $result = array();

            foreach ($lists as $list) {
                $result[] = array(
                    'id' => (int) $list->getId(),
                    'name' => $list->getName(),
                    'name_plural' => $list->getPluralName(),
                    'item_count' => (int) $list->getNumItems()
                );
            }

            return $this->response(200, json_encode(array(
                'lists' => $result
            )), 'application/json');
        } catch (Throwable $t) {
            return $this->response(500, json_encode(array(
                'error' => $t->getMessage()
            )), 'application/json');
        }
    }

    function create($id, $format='json') {
        try {
            if (!($key = $this->requireApiKey()))
                return $this->exerr(401, 'API key not authorized');

            $data = $this->getRequest('json');
            if (!$data)
                return $this->exerr(400, 'JSON body required');

            if (!isset($data['values']) || !is_array($data['values']))
                return $this->exerr(400, 'values array required');

            // Determine if we should update properties of existing items
            $updateExisting = !empty($data['update_existing']);

            $list = $this->lookupList($data, $id);
            if (!$list)
                return $this->exerr(404, 'Project list not found');

            $inserted = 0;
            $skipped = 0;
            $errors = array();

            foreach ($data['values'] as $raw) {
                $itemData = $this->normalizeValue($raw);
                $value = $itemData['value'];
                $extra = $itemData['extra'];

                if ($value === '') {
                    $skipped++;
                    continue;
                }

                // Skip if extra (e.g., project_id) is provided and already exists
                if ($extra !== null && $list->getItem($extra, true)) {
                    $skipped++;
                    continue;
                }

                // Handle existing items
                if ($item = $list->getItem($value)) {
                    if ($updateExisting && !empty($itemData['properties'])) {
                        $existing = $item->getConfiguration();
                        $merged = array_merge($existing ?: array(), $itemData['properties']);
                        $item->set('properties', JsonDataEncoder::encode($merged));
                        
                        if ($item->save())
                            $inserted++;
                        else
                            $skipped++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                // Add new item
                $item = $list->addItem(array(
                    'sort' => 0,
                    'value' => $value,
                    'extra' => $extra === null ? '' : $extra,
                    'properties' => $itemData['properties'],
                ), $errors);

                if ($item && $item->save()) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }

            return $this->response(201, json_encode(array(
                'list_id' => (int) $list->getId(),
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => $errors
            )), 'application/json');
        } catch (Throwable $t) {
            return $this->response(500, json_encode(array(
                'error' => $t->getMessage()
            )), 'application/json');
        }
    }
}

?>
