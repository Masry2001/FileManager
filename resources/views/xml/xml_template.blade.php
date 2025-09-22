{!! '<' . '?xml version="1.0" encoding="UTF-8"?>' !!}

<distribution>
    @foreach($files as $file)
        <asset>
            <title>{{ $file->original_name ?? '' }}</title>
            <path>{{ $file->path ?? '' }}</path>
            <extension>{{ $file->extension ?? '' }}</extension>
            <mime-type>{{ $file->{'mime-type'} ?? '' }}</mime-type>
            <size>{{ $file->size ?? 0 }}</size>
            <description>{{ $file->description ?? '' }}</description>
        </asset>
    @endforeach
</distribution>