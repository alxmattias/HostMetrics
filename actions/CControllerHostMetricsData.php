<?php

namespace Modules\HostMetrics\Actions;

use CController;
use CControllerResponseData;
use API;

class CControllerHostMetricsData extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = ['hostids' => 'array_id'];
        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool { return true; }

    private function formatBytes(float $bytes): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    protected function doAction(): void {
        $hostids = $this->getInput('hostids', []);
        if (empty($hostids)) {
            header('Content-Type: application/json');
            echo json_encode(['metrics' => []]);
            exit;
        }

        try {
            $items = API::Item()->get([
                'output' => ['hostid', 'key_', 'lastvalue'],
                'hostids' => $hostids,
                'search' => ['key_' => ['cpu', 'memory', 'vfs.fs', 'perf_counter']], // Búsqueda amplia incluyendo contadores Windows
                'searchByAny' => true,
                'monitored' => true
            ]);

            $metrics = [];
            foreach ($items as $item) {
                $hid = $item['hostid'];
                $key = $item['key_'];
                $val = $item['lastvalue'];

                if (!isset($metrics[$hid])) $metrics[$hid] = [];

                // CPU UTIL: Linux o Windows (Processor Time)
                if (strpos($key, 'system.cpu.util') !== false || stripos($key, 'Processor Time') !== false) {
                    $metrics[$hid]['cpu_util'] = round((float)$val, 2);
                } 
                // CORES: system.cpu.num es estándar, pero a veces varía
                if (strpos($key, 'system.cpu.num') !== false) {
                    $metrics[$hid]['cpu_cores'] = (int)$val;
                }
                // MEMORIA UTILIZACIÓN
                if (strpos($key, 'vm.memory.utilization') !== false || strpos($key, 'vm.memory.size[pused]') !== false) {
                    $metrics[$hid]['memory_util'] = round((float)$val, 2);
                } elseif (strpos($key, 'vm.memory.size[pavailable]') !== false) {
                    // Si Windows da 'disponible', calculamos el 'uso'
                    if (!isset($metrics[$hid]['memory_util'])) {
                        $metrics[$hid]['memory_util'] = round(100 - (float)$val, 2);
                    }
                }
                // MEMORIA BYTES
                if (strpos($key, 'vm.memory.size[available]') !== false || strpos($key, 'vm.memory.size[free]') !== false) {
                    $metrics[$hid]['memory_available'] = $this->formatBytes((float)$val);
                }
                if (strpos($key, 'vm.memory.size[total]') !== false) {
                    $metrics[$hid]['memory_total'] = $this->formatBytes((float)$val);
                }
                // DISCO: Captura '/' para Linux y 'C:' para Windows
                if (strpos($key, 'vfs.fs.size') !== false && strpos($key, 'pused') !== false && 
                   (strpos($key, '[/,') !== false || stripos($key, '[C:,') !== false)) {
                    $metrics[$hid]['disk'] = round((float)$val, 2);
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['metrics' => $metrics]);
            exit;
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}