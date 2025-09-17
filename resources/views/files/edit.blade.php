{{-- resources/views/files/edit.blade.php --}}

<x-layout>
    <div class="w-full max-w-3xl">
        <h1 class="text-3xl font-bold mb-6 text-center">📂 File Manager</h1>


        <div class="max-w-lg mx-auto bg-gray-800 p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-bold text-white mb-4">Edit File</h2>

            <form action="{{ route('files.update', $file) }}" method="POST">
                @csrf
                @method('PUT')

                {{-- Original name --}}
                <div class="mb-4">
                    <label for="original_name" class="block text-gray-300">File Name</label>
                    <input type="text" name="original_name" id="original_name"
                        value="{{ old('original_name', $file->original_name) }}"
                        class="w-full px-3 py-2 rounded bg-gray-700 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('original_name')
                        <p class="text-red-400 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="mb-4">
                    <label for="description" class="block text-gray-300">Description</label>
                    <input type="text" name="description" id="description"
                        value="{{ old('description', $file->description) }}"
                        class="w-full px-3 py-2 rounded bg-gray-700 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('description')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-between">
                    <a href="{{ route('files.index') }}"
                        class="bg-gray-600 hover:bg-gray-500 px-4 py-2 rounded text-white">
                        Cancel
                    </a>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-500 px-4 py-2 rounded text-white">
                        Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layout>