import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router } from "@inertiajs/react";
import React, { useEffect, useState } from "react";

// Define the schema for the form
const invoiceSchema = z.object({
    membership_id: z.any().optional(),
    months: z.any(),
    billDate: z.string().min(1, { message: "Bill date is required!" }),
    creationDate: z.any().optional(),
    totalAmount: z.any().optional(),
    amountPaid: z.any().optional(),
    rest: z.any().optional(),
    student_id: z.any().optional(),
    offer_id: z.any().optional(),
    endDate: z.string().optional(),
    includePartialMonth: z.boolean().optional(),
    partialMonthAmount: z.number().optional(),
    last_payment_date: z.any().optional()
});

const InvoicesForm = ({ type, data, setOpen, StudentMemberships = [], studentId }) => {
    console.log("Data in the form:", data);
    const today = new Date();
    const firstOfNextMonth = new Date(today.getFullYear(), today.getMonth() + 1, 1);
    const formattedFirstOfNextMonth = firstOfNextMonth.toISOString().split("T")[0];
    const todayFormatted = today.toISOString().split("T")[0];

    // State to track whether to include partial month
    const [includePartialMonth, setIncludePartialMonth] = useState(data?.includePartialMonth || false);

    // State to track loading
    const [loading, setLoading] = useState(false);

    // Track the initial amountPaid value
    const [initialAmountPaid, setInitialAmountPaid] = useState(data?.amountPaid || 0);

    // Format date to display in a more readable way
    const formatDateForDisplay = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    };

    const {
        register,
        handleSubmit,
        watch,
        setValue,
        formState: { errors },
    } = useForm({
        resolver: zodResolver(invoiceSchema),
        defaultValues: {
            membership_id: data?.membership_id || "",
            months: data?.months || 1,
            billDate: data?.billDate ? new Date(data.billDate).toISOString().split('T')[0] : formattedFirstOfNextMonth,
            creationDate: data?.creationDate || todayFormatted,
            totalAmount: data?.totalAmount || 0,
            amountPaid: data?.amountPaid || 0,
            rest: data?.rest || 0,
            student_id: studentId,
            offer_id: data?.offer_id || "",
            endDate: data?.endDate || "",
            includePartialMonth: data?.includePartialMonth || false,
            partialMonthAmount: data?.partialMonthAmount || 0,
            last_payment_date: data?.last_payment_date || "",
        },
    });

    const selectedMembershipId = watch("membership_id");
    const months = watch("months");
    const amountPaid = watch("amountPaid");
    const watchIncludePartialMonth = watch("includePartialMonth");

    // Find the selected membership
    const selectedMembership = StudentMemberships.find(
        (membership) => membership.id === parseInt(selectedMembershipId)
    );

    // Calculate the partial month amount
    const calculatePartialMonthAmount = () => {
        if (!selectedMembership || !includePartialMonth) return 0;
        const daysInCurrentMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
        const remainingDays = (firstOfNextMonth - today) / (1000 * 60 * 60 * 24);
        const dailyRate = selectedMembership.price / daysInCurrentMonth;
        return Math.round(dailyRate * remainingDays);
    };

    // Calculate the total amount (partial month + full months)
    const partialMonthAmount = calculatePartialMonthAmount();
    const fullMonthsAmount = selectedMembership ? Math.round(selectedMembership.price) * months : 0;
    const totalAmount = includePartialMonth ? fullMonthsAmount + partialMonthAmount : fullMonthsAmount;
    const restAmount = totalAmount - (amountPaid ? Math.round(Number(amountPaid)) : 0);

    // Function to calculate the billing date based on includePartialMonth
    const calculateBillingDate = () => {
        return includePartialMonth ? todayFormatted : formattedFirstOfNextMonth;
    };

    // Function to calculate the end date (last day of the month)
    const calculateEndDate = () => {
        let startMonth, startYear;
        if (includePartialMonth) {
            startMonth = today.getMonth() + 1;
            startYear = today.getFullYear();
        } else {
            startMonth = today.getMonth() + 1 + parseInt(months) - 1;
            startYear = today.getFullYear();
        }
        while (startMonth > 11) {
            startMonth -= 12;
            startYear += 1;
        }
        const lastDay = new Date(startYear, startMonth + 1, 0);
        return lastDay.toISOString().split("T")[0];
    };

    // Update values when dependencies change
    useEffect(() => {
        const newBillingDate = calculateBillingDate();
        setValue("billDate", newBillingDate);
        setValue("partialMonthAmount", partialMonthAmount);
        setValue("totalAmount", totalAmount);
        setValue("rest", restAmount);
        setValue("student_id", studentId);

        // Update offer_id based on the selected membership
        if (selectedMembership) {
            setValue("offer_id", selectedMembership.offer_id);
        }

        // Calculate and update the end date
        const endDate = calculateEndDate();
        setValue("endDate", endDate);

        // Update last_payment_date only if amountPaid has changed
        if (amountPaid !== initialAmountPaid) {
            setValue("last_payment_date", new Date().toISOString().slice(0, 19).replace('T', ' '));
        }
    }, [selectedMembershipId, months, amountPaid, includePartialMonth, setValue, selectedMembership]);

    // Handle partial month checkbox change
    const handlePartialMonthChange = (e) => {
        setIncludePartialMonth(e.target.checked);
        setValue("includePartialMonth", e.target.checked);
    };

    const onSubmit = (formData) => {
        // Ensure amounts are integers
        formData.totalAmount = Math.round(formData.totalAmount);
        formData.amountPaid = Math.round(Number(formData.amountPaid));
        formData.rest = Math.round(formData.rest);
        formData.partialMonthAmount = Math.round(formData.partialMonthAmount);

        // Update last_payment_date only if amountPaid has changed
        formData.last_payment_date =
            data?.amountPaid !== formData.amountPaid
                ? new Date().toISOString().slice(0, 19).replace('T', ' ')
                : data.last_payment_date;

        setLoading(true);
        console.log("Form submitted with data:", formData);

        if (type === "create") {
            router.post("/invoices", formData, {
                onSuccess: () => {
                    setOpen(false);
                    setLoading(false);
                },
                onError: () => {
                    setLoading(false);
                },
            });
        } else if (type === "update") {
            router.put(`/invoices/${data.id}`, formData, {
                onSuccess: () => {
                    setOpen(false);
                    setLoading(false);
                },
                onError: () => {
                    setLoading(false);
                },
            });
        }
    };

    const onError = (errors) => {
        console.log("Form errors:", errors);
        setLoading(false);
    };

    // Calculate formatted dates for display
    const nextMonthLastDay = new Date(today.getFullYear(), today.getMonth() + 1 + 1, 0);
    const formattedNextMonthName = nextMonthLastDay.toLocaleDateString('en-US', { month: 'long' })
    const handleAutoFill = () => {
        
        setValue("amountPaid", totalAmount);
        setValue("rest", 0);
    }
    return (
        <form
            className="flex flex-col gap-6 p-6 bg-white shadow-lg rounded-lg"
            onSubmit={handleSubmit(onSubmit, onError)}
        >
            {/* Header with breadcrumb and title */}
            <div className="border-b pb-4">
                <div className="flex items-center text-xs text-gray-500 mb-2">
                    <span>Invoices</span>
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-3 w-3 mx-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                    <span className="text-blue-600">{type === "create" ? "New Invoice" : "Edit Invoice"}</span>
                </div>
                <h1 className="text-2xl font-semibold text-gray-800">
                    {type === "create" ? "Create New Invoice" : "Update Invoice"}
                </h1>
            </div>

            {/* Info callout before form starts */}
            {/* {type === "create" && (
                <div className="bg-blue-50 p-4 rounded-lg border-l-4 border-blue-500">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <svg className="h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2h-1V9a1 1 0 00-1-1z" clipRule="evenodd" />
                            </svg>
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-blue-800">
                                Create an invoice by selecting a membership and payment period. All amounts are in Dirhams (DH).
                            </p>
                        </div>
                    </div>
                </div>
            )} */}

            {/* Main form content in sections */}
            <div className="grid grid-cols-1 gap-6">
                {/* Membership Selection Section */}
                <div className="bg-gray-50 p-4 rounded-lg">
                    <h2 className="text-lg font-medium text-gray-800 mb-4">Membership Details</h2>

                    <div className="space-y-4">
                        {/* Membership Select Field */}
                        <div className="flex flex-col gap-2">
                            <label htmlFor="membership_id" className="text-sm font-medium text-gray-700">
                                Select Membership <span className="text-red-500">*</span>
                            </label>
                            <select
                                id="membership_id"
                                {...register("membership_id")}
                                className="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {
                                    type === "update" ? (
                                        <option value={data.membership_id}>{StudentMemberships.find((membership) => membership.id === data.membership_id).offer_name} (Price: {Math.round(StudentMemberships.find((membership) => membership.id === data.membership_id).price)} DH)</option>
                                    ) : (
                                        <>
                                            <option value="">Select a membership</option>
                                            {StudentMemberships.map((membership) => (
                                                <option 
                                                    key={membership.id} 
                                                    value={membership.id}
                                                    className={membership.payment_status !== "paid" ? "bg-amber-50 font-medium" : ""}
                                                >
                                                    {membership.offer_name} (Price: {Math.round(membership.price)} DH)
                                                    {membership.payment_status !== "paid" && " - Unpaid"}
                                                </option>
                                            ))}
                                        </>
                                    )
                                }
                            </select>
                            {errors.membership_id && (
                                <span className="text-sm text-red-500">{errors.membership_id.message}</span>
                            )}
                        </div>

                        {selectedMembership && (
                            <div className="bg-white p-3 rounded-md border border-gray-200 mt-2">
                                <h3 className="font-medium text-gray-700 mb-2">Selected Membership Summary</h3>
                                <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <div className="text-gray-600">Membership:</div>
                                    <div className="font-medium">{selectedMembership.offer_name}</div>
                                    <div className="text-gray-600">Monthly Price:</div>
                                    <div className="font-medium">{Math.round(selectedMembership.price)} DH</div>
                                    {selectedMembership.payment_status !== "paid" && (
                                        <>
                                            <div className="text-gray-600">Payment Status:</div>
                                            <div className="font-medium text-amber-600">Unpaid</div>
                                        </>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Number of Months Input Field */}
                        <div className="flex flex-col gap-2">
                            <label htmlFor="months" className="text-sm font-medium text-gray-700">
                                Number of Months <span className="text-red-500">*</span>
                            </label>
                            <select
                                id="months"
                                {...register("months")}
                                className="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((num) => (
                                    <option key={num} value={num}>
                                        {num} {num === 1 ? 'month' : 'months'}
                                    </option>
                                ))}
                            </select>
                            {errors.months && (
                                <span className="text-sm text-red-500">{errors.months.message}</span>
                            )}
                        </div>

                        {/* Partial Month Option */}
                        {selectedMembership && (
                            <div className="mt-2">
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        id="includePartialMonth"
                                        checked={includePartialMonth}
                                        onChange={handlePartialMonthChange}
                                        className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                    />
                                    <label htmlFor="includePartialMonth" className="text-sm font-medium text-gray-700">
                                        Include partial month payment (today until end of this month)
                                    </label>
                                </div>

                                {/* Partial Month Amount Display */}
                                {includePartialMonth && selectedMembership && (
                                    <div className="bg-blue-50 p-3 rounded-md border border-blue-200 mt-2">
                                        <div className="flex items-center">
                                            <svg className="h-5 w-5 text-blue-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <div>
                                                <p className="text-sm text-blue-800">
                                                    Partial month payment: <span className="font-medium">{partialMonthAmount} DH</span>
                                                    ({Math.ceil((firstOfNextMonth - today) / (1000 * 60 * 60 * 24))} days remaining in current month)
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* Billing Details Section */}
                <div className="bg-gray-50 p-4 rounded-lg">
                    <h2 className="text-lg font-medium text-gray-800 mb-4">Billing Details</h2>

                    <div className="space-y-4">
                        {/* Bill Date Field */}
                        <div className="flex flex-col gap-2">
                            <label htmlFor="billDate" className="text-sm font-medium text-gray-700">
                                Bill Date <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                id="billDate"
                                {...register("billDate")}
                                className="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            {errors.billDate && (
                                <span className="text-sm text-red-500">{errors.billDate.message}</span>
                            )}
                        </div>

                        {/* End Date Field (Read-only) */}
                        <div className="flex flex-col gap-2">
                            <label htmlFor="endDate" className="text-sm font-medium text-gray-700">
                                End Date
                            </label>
                            <input
                                type="date"
                                id="endDate"
                                {...register("endDate")}
                                className="p-2 border border-gray-200 rounded-md bg-gray-100"
                                readOnly
                            />
                            <p className="text-xs text-gray-500">
                                Membership valid until: {formatDateForDisplay(watch("endDate"))}
                            </p>
                        </div>

                        {/* Amount Fields */}
                        {selectedMembership && (
                            <div className="space-y-4">
                                {/* Total Amount (Read-only) */}
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="totalAmount" className="text-sm font-medium text-gray-700">
                                        Total Amount (DH)
                                    </label>
                                    <input
                                        type="number"
                                        id="totalAmount"
                                        {...register("totalAmount")}
                                        className="p-2 border border-gray-200 rounded-md bg-gray-100"
                                        readOnly
                                    />
                                    <p className="text-xs text-gray-500">
                                        {includePartialMonth ?
                                            `${partialMonthAmount} DH (partial month) + ${fullMonthsAmount} DH (${months} ${parseInt(months) === 1 ? 'month' : 'months'})` :
                                            `${fullMonthsAmount} DH (${months} ${parseInt(months) === 1 ? 'month' : 'months'})`}
                                    </p>
                                </div>

                                {/* Amount Paid Field */}
                                <div className="flex flex-col gap-2">
                                    {/* Label */}
                                    <label htmlFor="amountPaid" className="text-sm font-medium text-gray-700">
                                        Amount Paid (DH)
                                    </label>

                                    {/* Input Container */}
                                    <div className="relative">
                                        {/* Amount Paid Input */}
                                        <input
                                            type="number"
                                            id="amountPaid"
                                            placeholder="0"
                                            {...register("amountPaid")}
                                            className="block w-full p-2 pr-10 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        />

                                        {/* Button with Icon */}
                                        <button
                                            type="button"
                                            onClick={handleAutoFill}
                                            id="autoFillButton"
                                            className="absolute inset-y-0 right-0 px-3 py-2 bg-blue-600 text-white rounded-r-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                className="h-5 w-5"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    strokeWidth="2"
                                                    d="M8 7h12m0 0l-4 4m4-4l-4-4m0 10H4m0 0l4 4m-4-4l4-4"
                                                />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                {/* Remaining Amount (Read-only) */}
                                <div className="flex flex-col gap-2">
                                    <label htmlFor="rest" className="text-sm font-medium text-gray-700">
                                        Remaining Amount (DH)
                                    </label>
                                    <input
                                        type="number"
                                        id="rest"
                                        {...register("rest")}
                                        className="p-2 border border-gray-200 rounded-md bg-gray-100"
                                        readOnly
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
            <input type="hidden" {...register("last_payment_date")} value={new Date().toISOString().split('T')[0]} />
            {/* Action Buttons */}
            <div className="flex justify-end gap-3 mt-4 pt-4 border-t">
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={loading}
                    className={`px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 ${loading ? "opacity-70 cursor-not-allowed" : ""
                        }`}
                >
                    {loading ? (
                        <div className="flex items-center justify-center">
                            <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing...
                        </div>
                    ) : (
                        `${type === "create" ? "Create Invoice" : "Update Invoice"}`
                    )}
                </button>
            </div>
        </form>
    );
};

export default InvoicesForm;