import React, { useEffect } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';

export default function UpdateUser({ userData, isUpdateOpen, setIsUpdateOpen }) {
    const updateUserData = userData.find((user) => user.id === isUpdateOpen.id);

    // Initialize the form with the user data
    const { data, setData, put, processing, errors, reset } = useForm({
        name: updateUserData?.name || "",
        email: updateUserData?.email || "",
        role: updateUserData?.role || "",
    });

    // Update the form data when `updateUserData` changes
    useEffect(() => {
        if (updateUserData) {
            setData({
                name: updateUserData.name,
                email: updateUserData.email,
                role: updateUserData.role,
            });
        }
    }, [updateUserData]);

    const submit = (e) => {
        e.preventDefault();

        // Submit the form data to the backend
        put(route('users.update', { user: updateUserData.id }), {
            onSuccess: () => {
                // Reset the form fields after successful submission
                reset();
                setIsUpdateOpen({ isOpen: false, id: null }); // Close the modal
            },
        });
    };

    return (
        <div>
            {/* Modal */}
            {isUpdateOpen.isOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                    <div className="bg-white rounded-lg shadow-lg w-full max-w-md relative">
                        {/* Close button */}
                        <button
                            onClick={() => setIsUpdateOpen({ isOpen: false, id: null })}
                            className="absolute top-3 right-3 text-gray-500 hover:text-gray-700"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        {/* Modal content */}
                        <div className="p-6">
                            <h2 className="text-2xl font-bold text-center mb-4">Update User</h2>
                            <p className="text-gray-600 text-center mb-6">Update user details</p>

                            <form onSubmit={submit} className="space-y-4">
                                {/* Name Field */}
                                <div>
                                    <InputLabel htmlFor="name" value="Name" />
                                    <TextInput
                                        id="name"
                                        name="name"
                                        value={data.name}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-black focus:ring-black"
                                        autoComplete="name"
                                        isFocused={true}
                                        onChange={(e) => setData('name', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.name} className="mt-1 text-sm text-red-500" />
                                </div>

                                {/* Email Field */}
                                <div>
                                    <InputLabel htmlFor="email" value="Email" />
                                    <TextInput
                                        id="email"
                                        type="email"
                                        name="email"
                                        value={data.email}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-black focus:ring-black"
                                        autoComplete="username"
                                        onChange={(e) => setData('email', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.email} className="mt-1 text-sm text-red-500" />
                                </div>

                                {/* Role Field */}
                                <div>
                                    <InputLabel htmlFor="role" value="Role" />
                                    <select
                                        id="role"
                                        name="role"
                                        value={data.role}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-black focus:ring-black"
                                        onChange={(e) => setData('role', e.target.value)}
                                        required
                                    >
                                        <option value="">Select Role</option>
                                        <option value="admin">Admin</option>
                                        <option value="assistant">Assistant</option>
                                        <option value="teacher">Teacher</option>
                                    </select>
                                    <InputError message={errors.role} className="mt-1 text-sm text-red-500" />
                                </div>

                                {/* Submit Button */}
                                <div className="flex items-center justify-center mt-6">
                                    <PrimaryButton
                                        className="px-4 py-2 bg-black text-white rounded-md hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-black focus:ring-offset-2"
                                        disabled={processing} // Disable button only during processing
                                    >
                                        Update User
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}