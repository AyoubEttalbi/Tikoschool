// Register.js
import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import UserForm from '@/Components/forms/UserForm'; // Import the UserForm component

export default function Register({ UserData,role, isModalOpen, setIsModalOpen, table }) {
  const userData = UserData || { name: '', email: '' };
  const { data, setData, post, processing, errors, reset } = useForm({
    
    name: userData.name || "",
    email: userData.email || "",
    password: '',
    password_confirmation: '',
    role: role || '',
  });

  // Function to generate a strong password
  const generateStrongPassword = () => {
    const length = 18; // Length of the generated password
    const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+~`|}{[]:;?><,./-=";
    let password = "";
    for (let i = 0, n = charset.length; i < length; ++i) {
      password += charset.charAt(Math.floor(Math.random() * n));
    }
    return password;
  };

  // Function to handle password generation and visibility toggle
  const handlePasswordAction = (field) => {
    if (field === 'password' && !data.password) {
      // Generate a new password if the field is empty
      const newPassword = generateStrongPassword();
      setData({
        ...data,
        password: newPassword,
        password_confirmation: newPassword,
      });
    }
  };

  const submit = (e) => {
   
    e.preventDefault();
    console.log("Submitting Data:", data); // Debugging

    // Submit the form data to the backend
    post(route('register.store'), {
      onSuccess: () => {
        // Reset the form fields after successful submission
        reset('password', 'password_confirmation');
        setIsModalOpen(false); // Close the modal
      },
    });
  };

  // Render only if isModalOpen is true
  if (!isModalOpen) {
    return null;
  }

  return (
    <div>
      {/* Modal for 'users' table */}
      {table === 'users' ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
          <div className="bg-white rounded-lg shadow-lg w-full max-w-md relative">
            {/* Close button */}
            <button
              onClick={() => setIsModalOpen(false)}
              className="absolute top-3 right-3 text-gray-500 hover:text-gray-700"
            >
              <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>

            {/* Modal content */}
            <div className="p-6">
              <h2 className="text-2xl font-bold text-center mb-4">Create User</h2>
              <p className="text-gray-600 text-center mb-6">Add a new user to the system</p>
              <UserForm
                data={data}
                setData={setData}
                errors={errors}
                processing={processing}
                onSubmit={submit}
                onPasswordAction={handlePasswordAction}
              />
            </div>
          </div>
        </div>
      ) : (
        // Render only the form for other cases
        <UserForm
          table={table}
          data={data}
          role={role}
          setData={setData}
          errors={errors}
          processing={processing}
          onSubmit={submit}
          onPasswordAction={handlePasswordAction}
        />
      )}
    </div>
  );
}