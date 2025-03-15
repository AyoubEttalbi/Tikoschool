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
});

const InvoicesForm = ({ type, data, setOpen, StudentMemberships = [], studentId }) => {
    const defaultBillingDate = new Date().toISOString().split("T")[0];
    const [includePartialMonth, setIncludePartialMonth] = useState(false);
    
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
            billDate: data?.billDate ? new Date(data.billDate).toISOString().split('T')[0] : defaultBillingDate,
            creationDate: data?.creationDate || new Date().toISOString().split("T")[0],
            totalAmount: data?.totalAmount || 0,
            amountPaid: data?.amountPaid || 0,
            rest: data?.rest || 0,
            student_id: studentId,
            offer_id: data?.offer_id || "",
            endDate: data?.endDate || "",
            includePartialMonth: data?.includePartialMonth || false,
            partialMonthAmount: data?.partialMonthAmount || 0,
        },
    });

    const selectedMembershipId = watch("membership_id");
    const months = watch("months");
    const amountPaid = watch("amountPaid");
    const billDate = watch("billDate");
    const watchIncludePartialMonth = watch("includePartialMonth");

    // Find the selected membership
    const selectedMembership = StudentMemberships.find(
        (membership) => membership.id === parseInt(selectedMembershipId)
    );
    
    // Calculate the partial month amount
    const calculatePartialMonthAmount = () => {
        if (!selectedMembership || !includePartialMonth) return 0;
        
        const today = new Date();
        const nextMonth = new Date(today.getFullYear(), today.getMonth() + 1, 1);
        const daysInCurrentMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate();
        const remainingDays = (nextMonth - today) / (1000 * 60 * 60 * 24);
        
        // Calculate the per-day rate based on the monthly price
        const dailyRate = selectedMembership.price / daysInCurrentMonth;
        
        // Calculate the prorated amount for the remaining days
        return Math.round(dailyRate * remainingDays * 100) / 100;
    };
    
    // Calculate the total amount (partial month + full months)
    const partialMonthAmount = calculatePartialMonthAmount();
    const fullMonthsAmount = selectedMembership ? selectedMembership.price * months : 0;
    const totalAmount = includePartialMonth ? fullMonthsAmount + partialMonthAmount : fullMonthsAmount;
    
    const restAmount = totalAmount - amountPaid;

    // Function to calculate the end date
    const calculateEndDate = (startDate, monthsToAdd) => {
        const date = new Date(startDate);
        
        // If including partial month, start from the 1st of next month
        if (includePartialMonth) {
            const firstOfNextMonth = new Date(date.getFullYear(), date.getMonth() + 1, 1);
            date.setTime(firstOfNextMonth.getTime());
        }
        
        date.setMonth(date.getMonth() + parseInt(monthsToAdd));
        return date.toISOString().split("T")[0];
    };

    // Update totalAmount, rest, offer_id, and endDate when values change
    useEffect(() => {
        setValue("partialMonthAmount", partialMonthAmount);
        setValue("totalAmount", totalAmount);
        setValue("rest", restAmount);
        setValue("student_id", studentId);
        

        // Update offer_id based on the selected membership
        if (selectedMembership) {
            setValue("offer_id", selectedMembership.offer_id);
        }

        // Calculate and update the end date
        if (billDate && months) {
            const endDate = calculateEndDate(billDate, months);
            setValue("endDate", endDate);
        }
        
    }, [selectedMembershipId, months, amountPaid, billDate, includePartialMonth, setValue, selectedMembership]);

    // Handle partial month checkbox change
    const handlePartialMonthChange = (e) => {
        setIncludePartialMonth(e.target.checked);
        setValue("includePartialMonth", e.target.checked);
    };
    console.log("iddddddddddddddddd",studentId);
    const onSubmit = (formData) => {
       
        console.log("Form submitted with data:", formData);
        if (type === "create") {
            router.post("/invoices", formData, {
                onSuccess: () => {
                    setOpen(false);
                },
            });
        } else if (type === "update") {
            router.put(`/invoices/${data.id}`, formData, {
                onSuccess: () => {
                    setOpen(false);
                },
            });
        }
    };

    const onError = (errors) => {
        console.log("Form errors:", errors);
    };

    return (
        <form
            className="flex flex-col gap-6 p-6 bg-white shadow-lg rounded-lg"
            onSubmit={handleSubmit(onSubmit, onError)}
        >
            <h1 className="text-2xl font-semibold text-gray-800">
                {type === "create" ? "Create a New Invoice" : "Update Invoice"}
                
            </h1>

            {/* Membership Select Field */}
            <div className="flex flex-col gap-2 justify-center ">
                <label htmlFor="membership_id" className="text-sm font-medium text-gray-700">
                    Select Membership
                </label>
                <select
                    id="membership_id"
                    {...register("membership_id")}
                    className="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <option value="">Select a membership</option>
                    {StudentMemberships.filter((membership) => membership.payment_status !== "paid").map((membership) => (
                        <option key={membership.id} value={membership.id}>
                        {membership.offer_name} (Price: {membership.price})
                        </option>
                    ))}
                    </select>
                {errors.membership_id && (
                    <span className="text-sm text-red-500">{errors.membership_id.message}</span>
                )}
            </div>

            {/* Partial Month Option */}
            {selectedMembership && (
                <div className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        id="includePartialMonth"
                        checked={includePartialMonth}
                        onChange={handlePartialMonthChange}
                        className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                    />
                    <label htmlFor="includePartialMonth" className="text-sm font-medium text-gray-700">
                        Include partial month payment (today until the 1st of next month)
                    </label>
                </div>
            )}

            {/* Partial Month Amount Display */}
            {includePartialMonth && selectedMembership && (
                <div className="bg-blue-50 p-3 rounded-md border border-blue-200">
                    <p className="text-sm text-blue-800">
                        Partial month payment: <span className="font-semibold">{partialMonthAmount.toFixed(2)} Dh</span> (from today until April 1, 2025)
                    </p>
                </div>
            )}

            <div className="grid grid-cols-2 gap-4 mt-7 justify-center ">
                {/* Number of Months Input Field */}
                <InputField
                    label="Number of Months"
                    name="months"
                    type="number"
                    register={register}
                    error={errors.months}
                    defaultValue={data?.months || 1}
                />

                {/* Bill Date Input Field */}
                <InputField
                    label="Bill Date"
                    name="billDate"
                    step={"0.01"}
                    type="date"
                    register={register}
                    error={errors.billDate}
                    defaultValue={data?.billDate || ""}
                />

                {/* Total Amount Input Field (Read-only, calculated automatically) */}
                <InputField
                    label="Total Amount"
                    name="totalAmount"
                    type="number"
                    step={"0.01"}
                    register={register}
                    error={errors.totalAmount}
                    defaultValue={data?.totalAmount || 0}
                    readOnly
                />

                {/* Amount Paid Input Field */}
                <InputField
                    label="Amount Paid"
                    name="amountPaid"
                    type="number"
                    register={register}
                    error={errors.amountPaid}
                    defaultValue={data?.amountPaid || 0}
                />

                {/* Rest Amount Input Field (Read-only, calculated automatically) */}
                <InputField
                    label="Rest Amount"
                    name="rest"
                    type="number"
                    step={"0.01"}
                    register={register}
                    error={errors.rest}
                    defaultValue={data?.rest || 0}
                    readOnly
                />

                {/* Offer Description Input Field */}
                <InputField
                    label="Offer Description"
                    name="offer"
                    register={register}
                    error={errors.offer}
                    defaultValue={data?.offer || ""}
                />

                {/* End Date Input Field (Read-only, calculated automatically) */}
                <InputField
                    label="End Date"
                    name="endDate"
                    type="date"
                    register={register}
                    error={errors.endDate}
                    defaultValue={data?.endDate || ""}
                    readOnly
                />
            </div>

            {/* Hidden fields */}
            <input
                type="hidden"
                {...register("offer_id")}
            />
            <input
                type="hidden"
                {...register("student_id")}
            />
            <input
                type="hidden"
                {...register("includePartialMonth")}
            />
            <input
                type="hidden"
                {...register("partialMonthAmount")}
            />

            <button
                type="submit"
                className="bg-blue-500 text-white p-3 rounded-md hover:bg-blue-600 transition"
            >
                {type === "create" ? "Create" : "Update"}
            </button>
        </form>
    );
};

export default InvoicesForm;