<x-layout>
    <div class="w-full max-w-3xl">
        <h1 class="text-3xl font-bold mb-6 text-center">📂 File Manager</h1>

        {{-- Success message --}}
        @if(session('success'))
            <div class="bg-green-600 text-white p-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        {{-- Upload form --}}
        <div class="bg-gray-800 p-6 rounded-lg shadow mb-8">
            <form action="{{ route('files.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block mb-1 font-semibold">Choose File</label>
                    <input type="file" name="file" class="block w-full text-sm text-gray-300
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-full file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-600 file:text-white
                        hover:file:bg-blue-500">
                    @error('file')
                        <p class="text-red-400 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block mb-1 font-semibold">Description</label>
                    <input type="text" name="description" placeholder="Optional description"
                        class="w-full px-3 py-2 rounded bg-gray-700 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('description')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-500 px-4 py-2 rounded font-semibold">
                    Upload
                </button>
            </form>
        </div>

        {{-- Files list --}}
        <div class="bg-gray-800 p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Uploaded Files</h2>
            @if($files->count())
                <table class="w-full text-left text-gray-300">
                    <thead class="text-gray-400 border-b border-gray-700">
                        <tr>
                            <th class="py-2">Name</th>
                            <th class="py-2">Description</th>
                            <th class="py-2">Size</th>
                            <th class="py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($files as $file)
                            <tr class="border-b border-gray-700">
                                <td class="py-2">{{ $file->original_name }}</td>
                                <td class="py-2">{{ $file->description ?? '-' }}</td>
                                <td class="py-2">{{ number_format($file->size / 1024, 2) }} KB</td>
                                <td class="py-2 flex space-x-2">
                                    {{-- Download button --}}
                                    <a href="{{ route('files.download', $file) }}"
                                        class="bg-green-600 hover:bg-green-500 px-3 py-1 rounded text-sm">
                                        Download
                                    </a>

                                    {{-- Delete button --}}
                                    <form action="{{ route('files.destroy', $file) }}" method="POST"
                                        onsubmit="return confirm('Delete this file?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="bg-red-600 hover:bg-red-500 px-3 py-1 rounded text-sm">
                                            Delete
                                        </button>
                                    </form>

                                    {{-- Edit button --}}
                                    <a href="{{ route('files.edit', $file) }}"
                                        class="bg-yellow-600 hover:bg-yellow-500 px-3 py-1 rounded text-sm text-white">
                                        Edit
                                    </a>

                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-gray-400">No files uploaded yet.</p>
            @endif
        </div>
    </div>
</x-layout>
