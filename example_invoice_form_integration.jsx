// EXAMPLE: How to integrate clean error handling into your invoice form
// Replace your existing invoice form submission with this pattern

import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import useInvoiceErrors from '../Hooks/useInvoiceErrors';
import InvoiceErrorDisplay from '../Components/invoice/InvoiceErrorDisplay';

const InvoiceFormExample = ({ studentMemberships }) => {
    const { register, handleSubmit, formState: { errors }, setValue, watch } = useForm();
    const { errors: invoiceErrors, suggestions, isVisible, handleApiError, clearErrors, showSuccess } = useInvoiceErrors();
    
    const [isSubmitting, setIsSubmitting] = useState(false);
    
    const totalAmount = watch('totalAmount') || 0;
    const amountPaid = watch('amountPaid') || 0;
    const rest = totalAmount - amountPaid;

    // Auto-calculate rest when amounts change
    React.useEffect(() => {
        if (totalAmount > 0 && amountPaid >= 0) {
            setValue('rest', Math.max(0, totalAmount - amountPaid));
        }
    }, [totalAmount, amountPaid, setValue]);

    const onSubmit = async (data) => {
        setIsSubmitting(true);
        clearErrors();

        try {
            router.post('/invoices', data, {
                onSuccess: (page) => {
                    showSuccess('Facture créée avec succès!');
                    // Reset form or redirect as needed
                },
                onError: (errors) => {
                    // Handle Laravel validation errors
                    handleApiError({ data: { errors, suggestions: [] } });
                },
                onFinish: () => {
                    setIsSubmitting(false);
                }
            });
        } catch (error) {
            handleApiError(error);
            setIsSubmitting(false);
        }
    };

    return (
        <div className="max-w-4xl mx-auto p-6">
            <h1 className="text-2xl font-bold mb-6">Créer une Facture</h1>
            
            {/* NEW: Clean Error Display */}
            {isVisible && (
                <InvoiceErrorDisplay
                    errors={invoiceErrors}
                    suggestions={suggestions}
                    onClose={clearErrors}
                    position="top"
                />
            )}

            <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                {/* Membership Selection */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Sélectionner l'Adhésion *
                        </label>
                        <select 
                            {...register('membership_id', { required: 'Sélectionnez une adhésion' })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">Choisir une adhésion...</option>
                            {studentMemberships?.map((membership) => (
                                <option key={membership.id} value={membership.id}>
                                    {membership.offer_name} - {membership.price}€
                                </option>
                            ))}
                        </select>
                        {errors.membership_id && (
                            <p className="mt-1 text-sm text-red-600">{errors.membership_id.message}</p>
                        )}
                    </div>

                    {/* Selected Months */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Mois Sélectionnés *
                        </label>
                        <input
                            {...register('selectedMonths')}
                            placeholder='["2025-09"]'
                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <p className="mt-1 text-xs text-gray-500">
                            Format JSON: ["2025-09", "2025-10"]
                        </p>
                    </div>
                </div>

                {/* Amount Fields */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Montant Total *
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            {...register('totalAmount', { required: 'Montant total requis' })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Montant Payé *
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            {...register('amountPaid', { required: 'Montant payé requis' })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Reste à Payer
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            value={rest}
                            readOnly
                            className="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md"
                        />
                    </div>
                </div>

                {/* Submit Button */}
                <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={isSubmitting}
                        className="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {isSubmitting ? 'Création...' : 'Créer la Facture'}
                    </button>
                </div>
            </form>
        </div>
    );
};

export default InvoiceFormExample;
