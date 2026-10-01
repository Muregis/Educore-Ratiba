<?php
declare(strict_types=1);

function diagnoseFetFailureV2(string $rawOutput, array $activityMeta): array
{
    $summary = [];
    $details = [];
    $trimmed = trim($rawOutput);

    if ($trimmed !== '') {
        $snippet = mb_strlen($trimmed) > 500 ? (mb_substr($trimmed, 0, 500) . '…') : $trimmed;
        $details[] = 'Engine output: ' . $snippet;
    }

    if ($trimmed === '') {
        $summary[] = 'The scheduling engine produced no output — it may be missing, crashed, '
            . 'or blocked by PHP (shell_exec / max_execution_time). Open Diagnostic to verify the engine.';
        return ['summary' => $summary, 'details' => $details];
    }

    if (preg_match('/FET engine not found/i', $trimmed)) {
        $summary[] = 'FET engine binary was not found on this server. '
            . 'The Docker image must install the Debian "fet" package and expose engine/fet-cl. '
            . 'Open Diagnostic for path checks.';
        return ['summary' => $summary, 'details' => $details];
    }
    if (preg_match('/not executable/i', $trimmed)) {
        $summary[] = 'FET engine exists but is not executable (permission or broken symlink). '
            . 'Open Diagnostic and fix engine/fet-cl permissions.';
        return ['summary' => $summary, 'details' => $details];
    }
    if (preg_match('/shell_exec|proc_open|disabled|Permission denied/i', $trimmed)) {
        $summary[] = 'PHP cannot run the scheduling engine (shell_exec/proc_open disabled or permission denied). '
            . 'Contact hosting support or adjust the container PHP config.';
        return ['summary' => $summary, 'details' => $details];
    }
    if (preg_match('/Cannot open|invalid|parse error|XML/i', $trimmed) && !preg_match('/Generation\s+successful/i', $trimmed)) {
        $summary[] = 'The engine rejected the input file (invalid or unreadable XML). '
            . 'Try Rebalance, then Generate again. If it persists, check Diagnostic.';
    }

    if (preg_match('/Total\s+conflicts?:\s*(\d+)/i', $trimmed, $m) && (int) $m[1] > 0) {
        $summary[] = "The engine finished but could not place {$m[1]} lesson(s) without conflicts.";
    }

    $badIds = [];
    if (preg_match_all('/[Aa]ctivity[_ ]?id[:\s]+(\d+)/', $trimmed, $m)) {
        foreach ($m[1] as $id) {
            $badIds[(int) $id] = true;
        }
    }
    if (preg_match_all('/[Aa]ctivity\s+(\d+)\s+not\s+scheduled/', $trimmed, $m)) {
        foreach ($m[1] as $id) {
            $badIds[(int) $id] = true;
        }
    }
    if (preg_match_all('/activity\s*=\s*(\d+)/i', $trimmed, $m)) {
        foreach ($m[1] as $id) {
            $badIds[(int) $id] = true;
        }
    }

    $badIds = array_keys($badIds);
    if (!empty($badIds) && !empty($activityMeta)) {
        $byClassSubject = [];
        foreach ($badIds as $id) {
            if (!isset($activityMeta[$id])) {
                continue;
            }
            $meta = $activityMeta[$id];
            $key = $meta['class_name'] . '|' . $meta['subject_name'] . '|' . $meta['teacher_name'];
            $byClassSubject[$key] = $meta;
        }
        if (empty($byClassSubject)) {
            $summary[] = 'The engine could not schedule some lessons (unidentified activities). '
                . 'Check teacher loads and subject hours in Teachers and Subjects.';
        } else {
            $summary[] = 'The engine could not schedule the following lessons without clashing — '
                . 'the usual causes are a teacher double-booked across classes, or more lessons '
                . 'than available slots:';
            foreach ($byClassSubject as $meta) {
                $details[] = "{$meta['class_name']} — {$meta['subject_name']} "
                    . "(teacher: {$meta['teacher_name']}) could not be placed.";
            }
        }
    } elseif (stripos($trimmed, 'not_scheduled') !== false || stripos($trimmed, 'could not') !== false) {
        $summary[] = 'Some lessons could not be scheduled. This usually means a teacher, room, or '
            . 'time slot is over-committed. Use Rebalance, then Generate again.';
    }

    if (empty($summary)) {
        $summary[] = 'Timetable generation failed. See engine output below (Diagnostic page has full engine health).';
    }

    return ['summary' => $summary, 'details' => $details];
}

function resolveFetEnginePath(string $configured): string
{
    $candidates = array_filter(array_unique([
        $configured,
        getenv('FET_ENGINE_PATH') ?: null,
        __DIR__ . '/../engine/fet-cl',
        dirname(__DIR__) . '/engine/fet-cl',
        '/usr/bin/fet-cl',
        '/usr/local/bin/fet-cl',
    ]));

    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    foreach ($candidates as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }
        $real = @realpath($path);
        if ($real && is_executable($real)) {
            return $real;
        }
    }

    return $configured !== '' ? $configured : '/usr/bin/fet-cl';
}

function runCommandCapture(string $binary, array $args): array
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $cmdParts = array_merge([$binary], $args);
    $cmd = implode(' ', array_map('escapeshellarg', $cmdParts));

    if (!in_array('proc_open', $disabled, true) && function_exists('proc_open')) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $out = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
            return ['output' => $out, 'exit_code' => $code, 'method' => 'proc_open'];
        }
    }

    if (in_array('shell_exec', $disabled, true) || !function_exists('shell_exec')) {
        return [
            'output' => 'shell_exec and proc_open are disabled in PHP — cannot run FET engine.',
            'exit_code' => null,
            'method' => 'blocked',
        ];
    }

    $raw = shell_exec($cmd . ' 2>&1');
    if ($raw === null) {
        return [
            'output' => 'shell_exec returned null (command blocked or failed to start).',
            'exit_code' => null,
            'method' => 'shell_exec',
        ];
    }
    return ['output' => (string) $raw, 'exit_code' => null, 'method' => 'shell_exec'];
}

function runFetEngineV2(string $xmlPath, string $engineExePath, string $outputDir, ?int $timeLimitSeconds = null): array
{
    if (!is_dir($outputDir)) {
        @mkdir($outputDir, 0777, true);
    }

    $engineExePath = resolveFetEnginePath($engineExePath);

    if (!file_exists($engineExePath)) {
        return [
            'success' => false,
            'raw_output' => "FET engine not found at: $engineExePath (also checked /usr/bin/fet-cl). Rebuild the Docker image so apt install fet succeeds.",
            'html_output_path' => null,
            'solution_xml_path' => null,
        ];
    }

    if (!is_executable($engineExePath)) {
        @chmod($engineExePath, 0755);
        if (!is_executable($engineExePath)) {
            return [
                'success' => false,
                'raw_output' => "FET engine exists but is not executable: $engineExePath",
                'html_output_path' => null,
                'solution_xml_path' => null,
            ];
        }
    }

    $phpBudget = ($timeLimitSeconds !== null && $timeLimitSeconds > 0)
        ? ((int) $timeLimitSeconds + 60)
        : 600;
    if (function_exists('set_time_limit')) {
        @set_time_limit($phpBudget);
    }
    @ini_set('max_execution_time', (string) $phpBudget);

    $args = [
        '--inputfile=' . $xmlPath,
        '--outputdir=' . $outputDir,
    ];
    if ($timeLimitSeconds !== null && $timeLimitSeconds > 0) {
        $args[] = '--timelimitseconds=' . (int) $timeLimitSeconds;
    }

    $captured = runCommandCapture($engineExePath, $args);
    $rawOutput = $captured['output'];
    if ($captured['exit_code'] !== null && $captured['exit_code'] !== 0 && $rawOutput === '') {
        $rawOutput = "FET exited with code {$captured['exit_code']} and no output (method={$captured['method']}).";
    } elseif ($captured['method'] !== '') {
        $rawOutput = rtrim($rawOutput) . "\n[runner={$captured['method']}"
            . ($captured['exit_code'] !== null ? " exit={$captured['exit_code']}" : '')
            . " engine={$engineExePath}]";
    }

    $success = stripos($rawOutput, 'Generation successful') !== false;

    $htmlPath = null;
    $solutionXmlPath = null;
    if ($success) {
        $timetablesDir = $outputDir . '/timetables';
        if (is_dir($timetablesDir)) {
            $allIndexFiles = [];
            foreach ((glob($timetablesDir . '/*', GLOB_ONLYDIR) ?: []) as $folder) {
                foreach ((glob($folder . '/*_index.html') ?: []) as $f) {
                    $allIndexFiles[] = $f;
                }
            }
            if (!empty($allIndexFiles)) {
                usort($allIndexFiles, static fn($a, $b) => filemtime($b) - filemtime($a));
                $htmlPath = $allIndexFiles[0];
                $folderOfNewest = dirname($htmlPath);
                $xmlCandidates = glob($folderOfNewest . '/*_activities.xml') ?: [];
                if (!empty($xmlCandidates)) {
                    usort($xmlCandidates, static fn($a, $b) => filemtime($b) - filemtime($a));
                    $solutionXmlPath = $xmlCandidates[0];
                } else {
                    error_log("FET generated HTML but no activities XML found in: $folderOfNewest");
                }
            } else {
                error_log("FET generation succeeded but no _index.html files found in: $timetablesDir");
            }
        } else {
            error_log("FET generation succeeded but timetables directory does not exist: $timetablesDir");
        }
    }

    return [
        'success' => $success,
        'raw_output' => $rawOutput,
        'html_output_path' => $htmlPath,
        'solution_xml_path' => $solutionXmlPath,
    ];
}
