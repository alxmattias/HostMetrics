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
        $fields = [
            'hostids' => 'array_id'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseData(['error' => $this->getValidationError()]));
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        // Permite el acceso a usuarios autenticados. Puedes ajustarlo según tu política de seguridad.
        return true;
    }

    /**
     * Convierte bytes a un formato legible (KB, MB, GB, etc.)
     */
    private function formatBytes(float $bytes): string {
        if ($bytes <= 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / pow(1024, $power);

        return round($value, 2) . ' ' . $units[$power];
    }

    protected function doAction(): void {
        $hostids = $this->getInput('hostids', []);

        if (empty($hostids)) {
            header('Content-Type: application/json');
            echo json_encode(['metrics' => []]);
            exit;
        }

        // Lista de llaves que buscamos (Compatibilidad Linux + Windows)
        $metric_keys = [
            'system.cpu.util',                         // CPU Linux / Windows (Agent)
            'system.cpu.num',                          // Cores Linux / Windows
            'perf_counter_en["\Processor(_Total)\% Processor Time"]', // CPU Windows Alternativo
            'vm.memory.utilization',                   // RAM % Linux
            'vm.memory.size[pavailable]',              // RAM % Disponible (Calcularemos el uso)
            'vm.memory.size[available]',               // RAM Disponible Bytes
            'vm.memory.size[total]',                   // RAM Total Bytes
            'vfs.fs.size[/,pused]',                    // Disco Linux (Raíz)
            'vfs.fs.size[C:,pused]'                    // Disco Windows (C:)
        ];

        try {
            $items = API::Item()->get([
                'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'units'],
                'hostids' => $hostids,
                'search' => [
                    'key_' => $metric_keys
                ],
                'searchByAny' => true,
                'monitored' => true
            ]);

            $metrics = [];
            foreach ($items as $item) {
                $hostid = $item['hostid'];
                $key = $item['key_'];
                $val = $item['lastvalue'];

                if (!isset($metrics[$hostid])) {
                    $metrics[$hostid] = [];
                }

                // --- PROCESAMIENTO DE CPU ---
                if (strpos($key, 'cpu.util') !== false || strpos($key, 'Processor Time') !== false) {
                    $metrics[$hostid]['cpu_util'] = round((float)$val, 2);
                } 
                elseif (strpos($key, 'cpu.num') !== false) {
                    $metrics[$hostid]['cpu_cores'] = (int)$val;
                } 

                // --- PROCESAMIENTO DE MEMORIA RAM ---
                elseif (strpos($key, 'memory.utilization') !== false) {
                    $metrics[$hostid]['memory_util'] = round((float)$val, 2);
                } 
                elseif (strpos($key, 'pavailable') !== false) {
                    // Si no existe la llave de "utilización", la calculamos: 100 - disponible
                    if (!isset($metrics[$hostid]['memory_util'])) {
                        $metrics[$hostid]['memory_util'] = round(100 - (float)$val, 2);
                    }
                } 
                elseif (strpos($key, 'size[available]') !== false) {
                    $metrics[$hostid]['memory_available'] = $this->formatBytes((float)$val);
                } 
                elseif (strpos($key, 'size[total]') !== false) {
                    $metrics[$hostid]['memory_total'] = $this->formatBytes((float)$val);
                } 

                // --- PROCESAMIENTO DE DISCO (pused detecta tanto / como C:) ---
                elseif (strpos($key, 'pused') !== false) {
                    $metrics[$hostid]['disk'] = round((float)$val, 2);
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