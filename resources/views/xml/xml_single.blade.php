{!! '<' . '?xml version="1.0" encoding="UTF-8"?>' !!}
<distribution>
    <asset>
        <title>{{ $file->original_name }}</title>
        <path>{{ $file->path }}</path>
        <extension>{{ $file->extension }}</extension>
        <mime-type>{{ $file->mime_type }}</mime-type>
        <size>{{ $file->size }}</size>
        <description>{{ $file->description }}</description>
    </asset>
</distribution>