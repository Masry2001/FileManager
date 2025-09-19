<?php

namespace App;


//ConvertFiles (Worker)
class ConvertFiles
{
    /**
     * Convert PDF to DOCX using CloudConvert API
     *
     * @param string $outputPath The desired output path for the converted file (without extension)
     * @param string $inputPath The path to the input PDF file
     * @return bool Success status
     */
    public static function convertPDFToDOCX($outputPath, $inputPath): bool
    {
        $apiKey = env('CLOUDCONVERT_API_KEY');
        $apiUrl = 'https://api.cloudconvert.com/v2';

        if (!$apiKey) {
            \Log::error('CloudConvert API key not found in environment variables');
            return false;
        }

        // Validate input file exists and is readable
        if (!file_exists($inputPath) || !is_readable($inputPath)) {
            \Log::error('Input file does not exist or is not readable', ['path' => $inputPath]);
            return false;
        }

        // Check file size (CloudConvert has limits)
        $fileSize = filesize($inputPath);
        if ($fileSize === false) {
            \Log::error('Cannot determine file size', ['path' => $inputPath]);
            return false;
        }

        \Log::info('Starting PDF to DOCX conversion', [
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'file_size' => $fileSize
        ]);

        try {
            // Step 1: Create a job
            $jobData = [
                'tasks' => [
                    'import-pdf' => [
                        'operation' => 'import/upload'
                    ],
                    'convert-pdf-to-docx' => [
                        'operation' => 'convert',
                        'input' => 'import-pdf',
                        'input_format' => 'pdf',
                        'output_format' => 'docx'
                    ],
                    'export-docx' => [
                        'operation' => 'export/url',
                        'input' => 'convert-pdf-to-docx'
                    ]
                ]
            ];

            $jobResponse = self::makeApiRequest($apiUrl . '/jobs', 'POST', $jobData, $apiKey);

            if (!$jobResponse || !isset($jobResponse['data']['id'])) {
                \Log::error('Failed to create CloudConvert job', ['response' => $jobResponse]);
                return false;
            }

            $jobId = $jobResponse['data']['id'];
            \Log::info('CloudConvert job created successfully', ['job_id' => $jobId]);

            // Step 2: Upload the PDF file
            $uploadTask = $jobResponse['data']['tasks'][0];

            // Validate upload task structure
            if (!isset($uploadTask['result']['form']['url']) || !isset($uploadTask['result']['form']['parameters'])) {
                \Log::error('Invalid upload task structure', ['task' => $uploadTask]);
                return false;
            }

            $uploadUrl = $uploadTask['result']['form']['url'];
            $uploadParameters = $uploadTask['result']['form']['parameters'];

            \Log::info('Starting file upload to CloudConvert', ['upload_url' => $uploadUrl]);

            $success = self::uploadFileToCloudConvert($uploadUrl, $uploadParameters, $inputPath);

            if (!$success) {
                \Log::error('Failed to upload file to CloudConvert');
                return false;
            }

            \Log::info('File uploaded successfully, waiting for conversion');

            // Step 3: Wait for job completion with enhanced status tracking
            $maxWaitTime = 300; // 5 minutes
            $waitTime = 0;
            $pollInterval = 5; // 5 seconds
            $lastStatus = null;

            while ($waitTime < $maxWaitTime) {
                sleep($pollInterval);
                $waitTime += $pollInterval;

                $jobStatusResponse = self::makeApiRequest($apiUrl . '/jobs/' . $jobId, 'GET', null, $apiKey);

                if (!$jobStatusResponse) {
                    \Log::warning('Failed to get job status, retrying...', ['job_id' => $jobId]);
                    continue;
                }

                $jobStatus = $jobStatusResponse['data']['status'];

                // Log status changes
                if ($jobStatus !== $lastStatus) {
                    \Log::info('Job status changed', [
                        'job_id' => $jobId,
                        'status' => $jobStatus,
                        'wait_time' => $waitTime
                    ]);
                    $lastStatus = $jobStatus;
                }

                if ($jobStatus === 'finished') {
                    \Log::info('Job finished successfully, looking for export task');

                    // Find the export task
                    foreach ($jobStatusResponse['data']['tasks'] as $task) {
                        \Log::debug('Checking task', ['task_name' => $task['name'], 'task_status' => $task['status']]);

                        if ($task['name'] === 'export-docx') {
                            if ($task['status'] !== 'finished') {
                                \Log::error('Export task not finished', ['task' => $task]);
                                return false;
                            }

                            if (!isset($task['result']['files'][0]['url'])) {
                                \Log::error('Export task missing download URL', ['task' => $task]);
                                return false;
                            }

                            $downloadUrl = $task['result']['files'][0]['url'];
                            \Log::info('Starting file download', ['download_url' => $downloadUrl]);

                            // Step 4: Download the converted file
                            $downloadSuccess = self::downloadConvertedFile($downloadUrl, $outputPath . '.docx');

                            if ($downloadSuccess) {
                                \Log::info('PDF to DOCX conversion completed successfully');
                            } else {
                                \Log::error('Failed to download converted file');
                            }

                            return $downloadSuccess;
                        }
                    }

                    \Log::error('Export task not found in finished job', ['tasks' => $jobStatusResponse['data']['tasks']]);
                    break;

                } elseif ($jobStatus === 'error') {
                    \Log::error('CloudConvert job failed', [
                        'job_id' => $jobId,
                        'full_response' => $jobStatusResponse
                    ]);

                    // Log individual task errors
                    if (isset($jobStatusResponse['data']['tasks'])) {
                        foreach ($jobStatusResponse['data']['tasks'] as $task) {
                            if ($task['status'] === 'error') {
                                \Log::error('Task failed', [
                                    'task_name' => $task['name'],
                                    'task_error' => $task['message'] ?? 'No error message',
                                    'task_details' => $task
                                ]);
                            }
                        }
                    }
                    break;
                }
            }

            if ($waitTime >= $maxWaitTime) {
                \Log::error('CloudConvert job timeout', [
                    'job_id' => $jobId,
                    'final_status' => $lastStatus,
                    'wait_time' => $waitTime
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('PDF to DOCX conversion error: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
        }

        return false;
    }

    /**
     * Make an API request to CloudConvert
     */
    private static function makeApiRequest($url, $method, $data = null, $apiKey = null): ?array
    {
        $headers = ['Content-Type: application/json'];

        if ($apiKey) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        \Log::error('CloudConvert API error', [
            'url' => $url,
            'http_code' => $httpCode,
            'response' => $response
        ]);

        return null;
    }

    /**
     * Upload file to CloudConvert
     */
    private static function uploadFileToCloudConvert($uploadUrl, $parameters, $filePath): bool
    {
        // Validate file before upload
        if (!file_exists($filePath)) {
            \Log::error('Upload file does not exist', ['path' => $filePath]);
            return false;
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            \Log::error('Upload file is empty or unreadable', ['path' => $filePath]);
            return false;
        }

        \Log::info('Uploading file to CloudConvert', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'upload_url' => $uploadUrl
        ]);

        $ch = curl_init();

        $postFields = $parameters;

        // FIXED: Create CURLFile with proper filename and mime type
        $postFields['file'] = new \CURLFile(
            $filePath,                    // file path
            'application/pdf',            // mime type
            'document.pdf'                // filename with proper extension
        );

        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Add more detailed curl options for debugging
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $uploadSize = curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);

        $curlError = curl_error($ch);
        curl_close($ch);

        \Log::info('Upload completed', [
            'http_code' => $httpCode,
            'upload_time' => $uploadTime,
            'upload_size' => $uploadSize,
            'curl_error' => $curlError
        ]);

        if ($httpCode >= 200 && $httpCode < 300) {
            \Log::info('File uploaded successfully');
            return true;
        }

        \Log::error('CloudConvert file upload error', [
            'http_code' => $httpCode,
            'response' => $response,
            'curl_error' => $curlError,
            'upload_url' => $uploadUrl,
            'file_size' => $fileSize
        ]);

        return false;
    }

    /**
     * Download the converted file from CloudConvert
     */
    private static function downloadConvertedFile($downloadUrl, $outputPath): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $fileContent !== false) {
            return file_put_contents($outputPath, $fileContent) !== false;
        }

        \Log::error('CloudConvert file download error', [
            'url' => $downloadUrl,
            'http_code' => $httpCode
        ]);

        return false;
    }
}
