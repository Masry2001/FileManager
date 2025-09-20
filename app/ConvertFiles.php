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
     * Convert Audio (MP3/WAV) to FLAC using CloudConvert API
     *
     * @param string $outputPath The desired output path for the converted file (without extension)
     * @param string $inputPath The path to the input audio file
     * @param string $inputFormat The input format (mp3, wav)
     * @return bool Success status
     */
    public static function convertAudioToFLAC($outputPath, $inputPath, $inputFormat): bool
    {
        $apiKey = env('CLOUDCONVERT_API_KEY');
        $apiUrl = 'https://api.cloudconvert.com/v2';

        if (!$apiKey) {
            \Log::error('CloudConvert API key not found in environment variables');
            return false;
        }

        // Validate input file exists and is readable
        if (!file_exists($inputPath) || !is_readable($inputPath)) {
            \Log::error('Input audio file does not exist or is not readable', ['path' => $inputPath]);
            return false;
        }

        // Check file size (CloudConvert has limits)
        $fileSize = filesize($inputPath);
        if ($fileSize === false) {
            \Log::error('Cannot determine audio file size', ['path' => $inputPath]);
            return false;
        }

        // Validate input format
        $inputFormat = strtolower($inputFormat);
        if (!in_array($inputFormat, ['mp3', 'wav'])) {
            \Log::error('Unsupported input audio format', ['format' => $inputFormat]);
            return false;
        }

        \Log::info('Starting Audio to FLAC conversion', [
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'input_format' => $inputFormat,
            'file_size' => $fileSize
        ]);

        try {
            // Step 1: Create a job
            $jobData = [
                'tasks' => [
                    'import-audio' => [
                        'operation' => 'import/upload'
                    ],
                    'convert-audio-to-flac' => [
                        'operation' => 'convert',
                        'input' => 'import-audio',
                        'input_format' => $inputFormat,
                        'output_format' => 'flac',
                        'options' => [
                            'audio_codec' => 'flac',
                            'audio_bitrate' => null, // Use original quality
                        ]
                    ],
                    'export-flac' => [
                        'operation' => 'export/url',
                        'input' => 'convert-audio-to-flac'
                    ]
                ]
            ];

            $jobResponse = self::makeApiRequest($apiUrl . '/jobs', 'POST', $jobData, $apiKey);

            if (!$jobResponse || !isset($jobResponse['data']['id'])) {
                \Log::error('Failed to create CloudConvert audio job', ['response' => $jobResponse]);
                return false;
            }

            $jobId = $jobResponse['data']['id'];
            \Log::info('CloudConvert audio job created successfully', ['job_id' => $jobId]);

            // Step 2: Upload the audio file
            $uploadTask = $jobResponse['data']['tasks'][0];

            // Validate upload task structure
            if (!isset($uploadTask['result']['form']['url']) || !isset($uploadTask['result']['form']['parameters'])) {
                \Log::error('Invalid audio upload task structure', ['task' => $uploadTask]);
                return false;
            }

            $uploadUrl = $uploadTask['result']['form']['url'];
            $uploadParameters = $uploadTask['result']['form']['parameters'];

            \Log::info('Starting audio file upload to CloudConvert', ['upload_url' => $uploadUrl]);

            $success = self::uploadAudioToCloudConvert($uploadUrl, $uploadParameters, $inputPath, $inputFormat);

            if (!$success) {
                \Log::error('Failed to upload audio file to CloudConvert');
                return false;
            }

            \Log::info('Audio file uploaded successfully, waiting for conversion');

            // Step 3: Wait for job completion with enhanced status tracking
            $maxWaitTime = 600; // 10 minutes for audio conversion (can take longer)
            $waitTime = 0;
            $pollInterval = 5; // 5 seconds
            $lastStatus = null;

            while ($waitTime < $maxWaitTime) {
                sleep($pollInterval);
                $waitTime += $pollInterval;

                $jobStatusResponse = self::makeApiRequest($apiUrl . '/jobs/' . $jobId, 'GET', null, $apiKey);

                if (!$jobStatusResponse) {
                    \Log::warning('Failed to get audio job status, retrying...', ['job_id' => $jobId]);
                    continue;
                }

                $jobStatus = $jobStatusResponse['data']['status'];

                // Log status changes
                if ($jobStatus !== $lastStatus) {
                    \Log::info('Audio job status changed', [
                        'job_id' => $jobId,
                        'status' => $jobStatus,
                        'wait_time' => $waitTime
                    ]);
                    $lastStatus = $jobStatus;
                }

                if ($jobStatus === 'finished') {
                    \Log::info('Audio job finished successfully, looking for export task');

                    // Find the export task
                    foreach ($jobStatusResponse['data']['tasks'] as $task) {
                        \Log::debug('Checking audio task', ['task_name' => $task['name'], 'task_status' => $task['status']]);

                        if ($task['name'] === 'export-flac') {
                            if ($task['status'] !== 'finished') {
                                \Log::error('Audio export task not finished', ['task' => $task]);
                                return false;
                            }

                            if (!isset($task['result']['files'][0]['url'])) {
                                \Log::error('Audio export task missing download URL', ['task' => $task]);
                                return false;
                            }

                            $downloadUrl = $task['result']['files'][0]['url'];
                            \Log::info('Starting audio file download', ['download_url' => $downloadUrl]);

                            // Step 4: Download the converted file
                            $downloadSuccess = self::downloadConvertedFile($downloadUrl, $outputPath . '.flac');

                            if ($downloadSuccess) {
                                \Log::info('Audio to FLAC conversion completed successfully');
                            } else {
                                \Log::error('Failed to download converted audio file');
                            }

                            return $downloadSuccess;
                        }
                    }

                    \Log::error('Audio export task not found in finished job', ['tasks' => $jobStatusResponse['data']['tasks']]);
                    break;

                } elseif ($jobStatus === 'error') {
                    \Log::error('CloudConvert audio job failed', [
                        'job_id' => $jobId,
                        'full_response' => $jobStatusResponse
                    ]);

                    // Log individual task errors
                    if (isset($jobStatusResponse['data']['tasks'])) {
                        foreach ($jobStatusResponse['data']['tasks'] as $task) {
                            if ($task['status'] === 'error') {
                                \Log::error('Audio task failed', [
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
                \Log::error('CloudConvert audio job timeout', [
                    'job_id' => $jobId,
                    'final_status' => $lastStatus,
                    'wait_time' => $waitTime
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('Audio to FLAC conversion error: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
        }

        return false;
    }

    /**
     * Upload audio file to CloudConvert with proper filename and mime type
     */
    private static function uploadAudioToCloudConvert($uploadUrl, $parameters, $filePath, $inputFormat): bool
    {
        // Validate file before upload
        if (!file_exists($filePath)) {
            \Log::error('Upload audio file does not exist', ['path' => $filePath]);
            return false;
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            \Log::error('Upload audio file is empty or unreadable', ['path' => $filePath]);
            return false;
        }

        // Determine mime type based on format
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav'
        ];

        $mimeType = $mimeTypes[$inputFormat] ?? 'audio/mpeg';
        $fileName = 'audio.' . $inputFormat;

        \Log::info('Uploading audio file to CloudConvert', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'input_format' => $inputFormat,
            'mime_type' => $mimeType,
            'filename' => $fileName,
            'upload_url' => $uploadUrl
        ]);

        $ch = curl_init();

        $postFields = $parameters;

        // Create CURLFile with proper filename and mime type for audio
        $postFields['file'] = new \CURLFile(
            $filePath,      // file path
            $mimeType,      // mime type
            $fileName       // filename with proper extension
        );

        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minutes for audio files
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $uploadSize = curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);

        $curlError = curl_error($ch);
        curl_close($ch);

        \Log::info('Audio upload completed', [
            'http_code' => $httpCode,
            'upload_time' => $uploadTime,
            'upload_size' => $uploadSize,
            'curl_error' => $curlError
        ]);

        if ($httpCode >= 200 && $httpCode < 300) {
            \Log::info('Audio file uploaded successfully');
            return true;
        }

        \Log::error('CloudConvert audio file upload error', [
            'http_code' => $httpCode,
            'response' => $response,
            'curl_error' => $curlError,
            'upload_url' => $uploadUrl,
            'file_size' => $fileSize
        ]);

        return false;
    }







    /**
     * Convert Video (MP4) to FLV using CloudConvert API
     *
     * @param string $outputPath The desired output path for the converted file (without extension)
     * @param string $inputPath The path to the input video file
     * @return bool Success status
     */
    public static function convertVideoToFLV($outputPath, $inputPath): bool
    {
        $apiKey = env('CLOUDCONVERT_API_KEY');
        $apiUrl = 'https://api.cloudconvert.com/v2';

        if (!$apiKey) {
            \Log::error('CloudConvert API key not found in environment variables');
            return false;
        }

        // Validate input file exists and is readable
        if (!file_exists($inputPath) || !is_readable($inputPath)) {
            \Log::error('Input video file does not exist or is not readable', ['path' => $inputPath]);
            return false;
        }

        // Check file size (CloudConvert has limits, especially for video)
        $fileSize = filesize($inputPath);
        if ($fileSize === false) {
            \Log::error('Cannot determine video file size', ['path' => $inputPath]);
            return false;
        }

        // Check if file is too large (CloudConvert free tier has limits)
        $maxSize = 1024 * 1024 * 1024; // 1GB limit
        if ($fileSize > $maxSize) {
            \Log::error('Video file too large for conversion', [
                'path' => $inputPath,
                'file_size' => $fileSize,
                'max_size' => $maxSize
            ]);
            return false;
        }

        \Log::info('Starting Video MP4 to FLV conversion', [
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'file_size' => $fileSize
        ]);

        try {
            // Step 1: Create a job
            $jobData = [
                'tasks' => [
                    'import-video' => [
                        'operation' => 'import/upload'
                    ],
                    'convert-video-to-flv' => [
                        'operation' => 'convert',
                        'input' => 'import-video',
                        'input_format' => 'mp4',
                        'output_format' => 'flv',
                        'options' => [
                            'video_codec' => 'flv1',
                            'audio_codec' => 'mp3',
                            'video_bitrate' => null, // Use reasonable quality
                            'audio_bitrate' => '128',
                            'fps' => null, // Keep original fps
                        ]
                    ],
                    'export-flv' => [
                        'operation' => 'export/url',
                        'input' => 'convert-video-to-flv'
                    ]
                ]
            ];

            $jobResponse = self::makeApiRequest($apiUrl . '/jobs', 'POST', $jobData, $apiKey);

            if (!$jobResponse || !isset($jobResponse['data']['id'])) {
                \Log::error('Failed to create CloudConvert video job', ['response' => $jobResponse]);
                return false;
            }

            $jobId = $jobResponse['data']['id'];
            \Log::info('CloudConvert video job created successfully', ['job_id' => $jobId]);

            // Step 2: Upload the video file
            $uploadTask = $jobResponse['data']['tasks'][0];

            // Validate upload task structure
            if (!isset($uploadTask['result']['form']['url']) || !isset($uploadTask['result']['form']['parameters'])) {
                \Log::error('Invalid video upload task structure', ['task' => $uploadTask]);
                return false;
            }

            $uploadUrl = $uploadTask['result']['form']['url'];
            $uploadParameters = $uploadTask['result']['form']['parameters'];

            \Log::info('Starting video file upload to CloudConvert', ['upload_url' => $uploadUrl]);

            $success = self::uploadVideoToCloudConvert($uploadUrl, $uploadParameters, $inputPath);

            if (!$success) {
                \Log::error('Failed to upload video file to CloudConvert');
                return false;
            }

            \Log::info('Video file uploaded successfully, waiting for conversion');

            // Step 3: Wait for job completion with enhanced status tracking
            $maxWaitTime = 1200; // 20 minutes for video conversion (takes much longer)
            $waitTime = 0;
            $pollInterval = 10; // 10 seconds (longer polling for video)
            $lastStatus = null;

            while ($waitTime < $maxWaitTime) {
                sleep($pollInterval);
                $waitTime += $pollInterval;

                $jobStatusResponse = self::makeApiRequest($apiUrl . '/jobs/' . $jobId, 'GET', null, $apiKey);

                if (!$jobStatusResponse) {
                    \Log::warning('Failed to get video job status, retrying...', ['job_id' => $jobId]);
                    continue;
                }

                $jobStatus = $jobStatusResponse['data']['status'];

                // Log status changes
                if ($jobStatus !== $lastStatus) {
                    \Log::info('Video job status changed', [
                        'job_id' => $jobId,
                        'status' => $jobStatus,
                        'wait_time' => $waitTime
                    ]);
                    $lastStatus = $jobStatus;
                }

                if ($jobStatus === 'finished') {
                    \Log::info('Video job finished successfully, looking for export task');

                    // Find the export task
                    foreach ($jobStatusResponse['data']['tasks'] as $task) {
                        \Log::debug('Checking video task', ['task_name' => $task['name'], 'task_status' => $task['status']]);

                        if ($task['name'] === 'export-flv') {
                            if ($task['status'] !== 'finished') {
                                \Log::error('Video export task not finished', ['task' => $task]);
                                return false;
                            }

                            if (!isset($task['result']['files'][0]['url'])) {
                                \Log::error('Video export task missing download URL', ['task' => $task]);
                                return false;
                            }

                            $downloadUrl = $task['result']['files'][0]['url'];
                            \Log::info('Starting video file download', ['download_url' => $downloadUrl]);

                            // Step 4: Download the converted file
                            $downloadSuccess = self::downloadConvertedFile($downloadUrl, $outputPath . '.flv');

                            if ($downloadSuccess) {
                                \Log::info('Video MP4 to FLV conversion completed successfully');
                            } else {
                                \Log::error('Failed to download converted video file');
                            }

                            return $downloadSuccess;
                        }
                    }

                    \Log::error('Video export task not found in finished job', ['tasks' => $jobStatusResponse['data']['tasks']]);
                    break;

                } elseif ($jobStatus === 'error') {
                    \Log::error('CloudConvert video job failed', [
                        'job_id' => $jobId,
                        'full_response' => $jobStatusResponse
                    ]);

                    // Log individual task errors
                    if (isset($jobStatusResponse['data']['tasks'])) {
                        foreach ($jobStatusResponse['data']['tasks'] as $task) {
                            if ($task['status'] === 'error') {
                                \Log::error('Video task failed', [
                                    'task_name' => $task['name'],
                                    'task_error' => $task['message'] ?? 'No error message',
                                    'task_details' => $task
                                ]);
                            }
                        }
                    }
                    break;
                }

                // Log progress for long-running video conversions
                if ($waitTime % 60 === 0) { // Every minute
                    \Log::info('Video conversion in progress', [
                        'job_id' => $jobId,
                        'status' => $jobStatus,
                        'elapsed_minutes' => $waitTime / 60
                    ]);
                }
            }

            if ($waitTime >= $maxWaitTime) {
                \Log::error('CloudConvert video job timeout', [
                    'job_id' => $jobId,
                    'final_status' => $lastStatus,
                    'wait_time' => $waitTime
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('Video MP4 to FLV conversion error: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
        }

        return false;
    }

    /**
     * Upload video file to CloudConvert with proper filename and mime type
     */
    private static function uploadVideoToCloudConvert($uploadUrl, $parameters, $filePath): bool
    {
        // Validate file before upload
        if (!file_exists($filePath)) {
            \Log::error('Upload video file does not exist', ['path' => $filePath]);
            return false;
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            \Log::error('Upload video file is empty or unreadable', ['path' => $filePath]);
            return false;
        }

        $mimeType = 'video/mp4';
        $fileName = 'video.mp4';

        \Log::info('Uploading video file to CloudConvert', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'file_size_mb' => round($fileSize / (1024 * 1024), 2),
            'mime_type' => $mimeType,
            'filename' => $fileName,
            'upload_url' => $uploadUrl
        ]);

        $ch = curl_init();

        $postFields = $parameters;

        // Create CURLFile with proper filename and mime type for video
        $postFields['file'] = new \CURLFile(
            $filePath,      // file path
            $mimeType,      // mime type
            $fileName       // filename with proper extension
        );

        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1800); // 30 minutes for video upload
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $uploadSize = curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);

        $curlError = curl_error($ch);
        curl_close($ch);

        \Log::info('Video upload completed', [
            'http_code' => $httpCode,
            'upload_time' => $uploadTime,
            'upload_time_minutes' => round($uploadTime / 60, 2),
            'upload_size' => $uploadSize,
            'curl_error' => $curlError
        ]);

        if ($httpCode >= 200 && $httpCode < 300) {
            \Log::info('Video file uploaded successfully');
            return true;
        }

        \Log::error('CloudConvert video file upload error', [
            'http_code' => $httpCode,
            'response' => $response,
            'curl_error' => $curlError,
            'upload_url' => $uploadUrl,
            'file_size' => $fileSize
        ]);

        return false;
    }








    /**
     * Convert Image (JPG/JPEG) to PNG using CloudConvert API
     *
     * @param string $outputPath The desired output path for the converted file (without extension)
     * @param string $inputPath The path to the input image file
     * @param string $inputFormat The input format (jpg, jpeg)
     * @return bool Success status
     */
    public static function convertImageToPng($outputPath, $inputPath, $inputFormat): bool
    {
        $apiKey = env('CLOUDCONVERT_API_KEY');
        $apiUrl = 'https://api.cloudconvert.com/v2';

        if (!$apiKey) {
            \Log::error('CloudConvert API key not found in environment variables');
            return false;
        }

        // Validate input file exists and is readable
        if (!file_exists($inputPath) || !is_readable($inputPath)) {
            \Log::error('Input image file does not exist or is not readable', ['path' => $inputPath]);
            return false;
        }

        // Check file size
        $fileSize = filesize($inputPath);
        if ($fileSize === false) {
            \Log::error('Cannot determine image file size', ['path' => $inputPath]);
            return false;
        }

        // Validate input format
        $inputFormat = strtolower($inputFormat);
        if (!in_array($inputFormat, ['jpg', 'jpeg'])) {
            \Log::error('Unsupported input image format', ['format' => $inputFormat]);
            return false;
        }

        // Check reasonable image size limits (50MB should be more than enough for images)
        $maxSize = 50 * 1024 * 1024; // 50MB
        if ($fileSize > $maxSize) {
            \Log::error('Image file too large for conversion', [
                'path' => $inputPath,
                'file_size' => $fileSize,
                'max_size' => $maxSize
            ]);
            return false;
        }

        \Log::info('Starting Image JPG/JPEG to PNG conversion', [
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'input_format' => $inputFormat,
            'file_size' => $fileSize,
            'file_size_kb' => round($fileSize / 1024, 2)
        ]);

        try {
            // Step 1: Create a job
            $jobData = [
                'tasks' => [
                    'import-image' => [
                        'operation' => 'import/upload'
                    ],
                    'convert-image-to-png' => [
                        'operation' => 'convert',
                        'input' => 'import-image',
                        'input_format' => $inputFormat === 'jpeg' ? 'jpg' : $inputFormat,
                        'output_format' => 'png',
                        'options' => [
                            'quality' => 100,           // Maximum quality
                            'strip' => false,           // Keep metadata
                            'auto_orient' => true,      // Auto-rotate based on EXIF
                        ]
                    ],
                    'export-png' => [
                        'operation' => 'export/url',
                        'input' => 'convert-image-to-png'
                    ]
                ]
            ];

            $jobResponse = self::makeApiRequest($apiUrl . '/jobs', 'POST', $jobData, $apiKey);

            if (!$jobResponse || !isset($jobResponse['data']['id'])) {
                \Log::error('Failed to create CloudConvert image job', ['response' => $jobResponse]);
                return false;
            }

            $jobId = $jobResponse['data']['id'];
            \Log::info('CloudConvert image job created successfully', ['job_id' => $jobId]);

            // Step 2: Upload the image file
            $uploadTask = $jobResponse['data']['tasks'][0];

            // Validate upload task structure
            if (!isset($uploadTask['result']['form']['url']) || !isset($uploadTask['result']['form']['parameters'])) {
                \Log::error('Invalid image upload task structure', ['task' => $uploadTask]);
                return false;
            }

            $uploadUrl = $uploadTask['result']['form']['url'];
            $uploadParameters = $uploadTask['result']['form']['parameters'];

            \Log::info('Starting image file upload to CloudConvert', ['upload_url' => $uploadUrl]);

            $success = self::uploadImageToCloudConvert($uploadUrl, $uploadParameters, $inputPath, $inputFormat);

            if (!$success) {
                \Log::error('Failed to upload image file to CloudConvert');
                return false;
            }

            \Log::info('Image file uploaded successfully, waiting for conversion');

            // Step 3: Wait for job completion (images convert quickly)
            $maxWaitTime = 120; // 2 minutes should be plenty for image conversion
            $waitTime = 0;
            $pollInterval = 3; // 3 seconds (shorter for quick image processing)
            $lastStatus = null;

            while ($waitTime < $maxWaitTime) {
                sleep($pollInterval);
                $waitTime += $pollInterval;

                $jobStatusResponse = self::makeApiRequest($apiUrl . '/jobs/' . $jobId, 'GET', null, $apiKey);

                if (!$jobStatusResponse) {
                    \Log::warning('Failed to get image job status, retrying...', ['job_id' => $jobId]);
                    continue;
                }

                $jobStatus = $jobStatusResponse['data']['status'];

                // Log status changes
                if ($jobStatus !== $lastStatus) {
                    \Log::info('Image job status changed', [
                        'job_id' => $jobId,
                        'status' => $jobStatus,
                        'wait_time' => $waitTime
                    ]);
                    $lastStatus = $jobStatus;
                }

                if ($jobStatus === 'finished') {
                    \Log::info('Image job finished successfully, looking for export task');

                    // Find the export task
                    foreach ($jobStatusResponse['data']['tasks'] as $task) {
                        \Log::debug('Checking image task', ['task_name' => $task['name'], 'task_status' => $task['status']]);

                        if ($task['name'] === 'export-png') {
                            if ($task['status'] !== 'finished') {
                                \Log::error('Image export task not finished', ['task' => $task]);
                                return false;
                            }

                            if (!isset($task['result']['files'][0]['url'])) {
                                \Log::error('Image export task missing download URL', ['task' => $task]);
                                return false;
                            }

                            $downloadUrl = $task['result']['files'][0]['url'];
                            \Log::info('Starting image file download', ['download_url' => $downloadUrl]);

                            // Step 4: Download the converted file
                            $downloadSuccess = self::downloadConvertedFile($downloadUrl, $outputPath . '.png');

                            if ($downloadSuccess) {
                                $convertedSize = filesize($outputPath . '.png');
                                \Log::info('Image JPG/JPEG to PNG conversion completed successfully', [
                                    'original_size' => $fileSize,
                                    'converted_size' => $convertedSize,
                                    'size_change' => $convertedSize ? round((($convertedSize - $fileSize) / $fileSize) * 100, 1) . '%' : 'unknown'
                                ]);
                            } else {
                                \Log::error('Failed to download converted image file');
                            }

                            return $downloadSuccess;
                        }
                    }

                    \Log::error('Image export task not found in finished job', ['tasks' => $jobStatusResponse['data']['tasks']]);
                    break;

                } elseif ($jobStatus === 'error') {
                    \Log::error('CloudConvert image job failed', [
                        'job_id' => $jobId,
                        'full_response' => $jobStatusResponse
                    ]);

                    // Log individual task errors
                    if (isset($jobStatusResponse['data']['tasks'])) {
                        foreach ($jobStatusResponse['data']['tasks'] as $task) {
                            if ($task['status'] === 'error') {
                                \Log::error('Image task failed', [
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
                \Log::error('CloudConvert image job timeout', [
                    'job_id' => $jobId,
                    'final_status' => $lastStatus,
                    'wait_time' => $waitTime
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('Image JPG/JPEG to PNG conversion error: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
        }

        return false;
    }

    /**
     * Upload image file to CloudConvert with proper filename and mime type
     */
    private static function uploadImageToCloudConvert($uploadUrl, $parameters, $filePath, $inputFormat): bool
    {
        // Validate file before upload
        if (!file_exists($filePath)) {
            \Log::error('Upload image file does not exist', ['path' => $filePath]);
            return false;
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            \Log::error('Upload image file is empty or unreadable', ['path' => $filePath]);
            return false;
        }

        // Determine mime type based on format
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg'
        ];

        $mimeType = $mimeTypes[$inputFormat] ?? 'image/jpeg';
        $fileName = 'image.' . ($inputFormat === 'jpeg' ? 'jpg' : $inputFormat);

        \Log::info('Uploading image file to CloudConvert', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'file_size_kb' => round($fileSize / 1024, 2),
            'input_format' => $inputFormat,
            'mime_type' => $mimeType,
            'filename' => $fileName,
            'upload_url' => $uploadUrl
        ]);

        $ch = curl_init();

        $postFields = $parameters;

        // Create CURLFile with proper filename and mime type for image
        $postFields['file'] = new \CURLFile(
            $filePath,      // file path
            $mimeType,      // mime type
            $fileName       // filename with proper extension
        );

        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 3 minutes for image upload
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $uploadSize = curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);

        $curlError = curl_error($ch);
        curl_close($ch);

        \Log::info('Image upload completed', [
            'http_code' => $httpCode,
            'upload_time' => $uploadTime,
            'upload_size' => $uploadSize,
            'curl_error' => $curlError
        ]);

        if ($httpCode >= 200 && $httpCode < 300) {
            \Log::info('Image file uploaded successfully');
            return true;
        }

        \Log::error('CloudConvert image file upload error', [
            'http_code' => $httpCode,
            'response' => $response,
            'curl_error' => $curlError,
            'upload_url' => $uploadUrl,
            'file_size' => $fileSize
        ]);

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
