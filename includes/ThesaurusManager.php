<?php
class ThesaurusManager {
    
    // Fonction interne pour détecter si le JSON est groupé (ex: "Adrar" => ["ville1", "ville2"])
    private static function isGroupedArray($array) {
        if (!is_array($array)) return false;
        foreach ($array as $k => $v) {
            if (is_string($k) && is_array($v)) return true;
        }
        return false;
    }

    public static function getList($filePath, $key = null) {
        if (!file_exists($filePath)) return [];
        
        $data = json_decode(file_get_contents($filePath), true);
        if (!is_array($data)) return [];
        
        $target = ($key === null) ? $data : ($data[$key] ?? []);
        $list = [];
        
        if (self::isGroupedArray($target)) {
            // Mode Dictionnaire (wilayas.json) : on aplatit virtuellement pour l'affichage
            foreach ($target as $group => $items) {
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $list[] = trim((string)$item);
                    }
                }
            }
        } else {
            // Mode Liste simple standard (thesaurus.json)
            foreach ($target as $item) {
                if (is_array($item)) {
                    $val = $item['nom'] ?? $item['titre'] ?? $item['value'] ?? reset($item);
                    $list[] = trim((string)$val);
                } else {
                    $list[] = trim((string)$item);
                }
            }
        }
        
        $list = array_filter($list, function($v) { return $v !== ''; });
        sort($list); 
        return array_values(array_unique($list));
    }

    public static function addTerm($filePath, $key, $term) {
        $term = trim($term);
        if ($term === '') return false;
        
        $data = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
        if (!is_array($data)) $data = [];
        
        $target = ($key === null) ? $data : ($data[$key] ?? []);
        
        if (self::isGroupedArray($target)) {
            // Si c'est un dictionnaire, on ajoute dans une clé spécifique pour ne pas casser la structure
            if (!isset($target['Nouveaux termes (à classer)'])) {
                $target['Nouveaux termes (à classer)'] = [];
            }
            if (!in_array($term, $target['Nouveaux termes (à classer)'])) {
                $target['Nouveaux termes (à classer)'][] = $term;
            }
        } else {
            // Liste simple standard
            if (!in_array($term, $target)) {
                $target[] = $term;
                sort($target);
            }
        }
        
        if ($key === null) $data = $target; else $data[$key] = $target;
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return true;
    }

    public static function editTerm($filePath, $key, $oldTerm, $newTerm) {
        $newTerm = trim($newTerm);
        if ($newTerm === '') return false;
        
        $data = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
        if (!is_array($data)) return false;
        
        $target = ($key === null) ? $data : ($data[$key] ?? []);
        $changed = false;
        
        if (self::isGroupedArray($target)) {
            // Mode Dictionnaire : On cherche dans chaque groupe (chaque Wilaya)
            foreach ($target as $group => &$items) {
                if (is_array($items)) {
                    $index = array_search($oldTerm, $items);
                    if ($index !== false) {
                        $items[$index] = $newTerm;
                        $changed = true;
                    }
                }
            }
        } else {
            // Mode Liste simple
            $index = array_search($oldTerm, $target);
            if ($index !== false) {
                $target[$index] = $newTerm;
                sort($target);
                $changed = true;
            }
        }
        
        if ($changed) {
            if ($key === null) $data = $target; else $data[$key] = $target;
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return true;
        }
        return false;
    }

    public static function deleteTerm($filePath, $key, $term) {
        $data = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
        if (!is_array($data)) return false;
        
        $target = ($key === null) ? $data : ($data[$key] ?? []);
        $changed = false;
        
        if (self::isGroupedArray($target)) {
            // Mode Dictionnaire : On cherche et on supprime dans le bon groupe
            foreach ($target as $group => &$items) {
                if (is_array($items)) {
                    $index = array_search($term, $items);
                    if ($index !== false) {
                        unset($items[$index]);
                        $items = array_values($items); // Réindexer les clés
                        $changed = true;
                    }
                }
            }
        } else {
            // Mode Liste simple
            $index = array_search($term, $target);
            if ($index !== false) {
                unset($target[$index]);
                $target = array_values($target);
                $changed = true;
            }
        }
        
        if ($changed) {
            if ($key === null) $data = $target; else $data[$key] = $target;
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return true;
        }
        return false;
    }
}