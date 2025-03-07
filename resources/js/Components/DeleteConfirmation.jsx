import { useState } from 'react';
import { useForm } from '@inertiajs/react';

const DeleteConfirmation = ({ id, route, onDelete, onClose }) => {
    const [isDeleting, setIsDeleting] = useState(false);

    const { delete: deleteResource, processing, errors } = useForm();

    const handleDelete = () => {
        setIsDeleting(true);
        deleteResource(`${route}/${id}`, {
            onSuccess: () => {
                onDelete(); // Call the onDelete callback
                setIsDeleting(false);
                onClose(); // Close the modal after deletion
            },
            onError: (errors) => {
                console.error('Error deleting item:', errors);
                setIsDeleting(false);
            },
        });
    };

    return (
        <div className="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-semibold text-gray-900">Confirm Deletion</h3>
                <button
                    className="p-2 rounded-full hover:bg-gray-100"
                    onClick={onClose} // Close the modal
                >
                    <svg
                        className="w-6 h-6 text-gray-500"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
            <div className="mb-6">
                <p className="text-sm text-gray-600">
                    Are you sure you want to delete: <strong>{id}</strong>? This action cannot be undone.
                </p>
            </div>
            <div className="flex justify-end space-x-4">
                <button
                    className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-500"
                    onClick={onClose} // Close the modal
                    disabled={isDeleting}
                >
                    Cancel
                </button>
                <button
                    className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                    onClick={handleDelete}
                    disabled={isDeleting}
                >
                    {isDeleting ? 'Deleting...' : 'Delete'}
                </button>
            </div>
        </div>
    );
};

export default DeleteConfirmation;