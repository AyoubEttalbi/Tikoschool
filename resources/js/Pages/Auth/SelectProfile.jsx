import { useForm, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

export default function SelectProfile({ schools }) {
    console.log('schools', schools);
    const { flash } = usePage().props;

    const { data, setData, errors, processing, reset } = useForm({
        school_id: '',
    });

    const submit = (e) => {
        e.preventDefault();

        router.post('/select-profile', data, {
            onSuccess: () => {
                console.log('Profile selected successfully!');
                console.log('data',data);
                reset();
            },
            onError: (errors) => {
                console.error(errors);
            },
        });
    };

    useEffect(() => {
        if (flash.message) {
            alert(flash.message); // Or use a toast system if you have one
        }

        if (flash.error) {
            alert(flash.error);
        }
    }, [flash]);

    return (
        <div className="min-h-screen flex items-center justify-center bg-gray-100">
            <form onSubmit={submit} className="bg-white p-6 rounded shadow w-full max-w-md">
                <h2 className="text-2xl font-semibold mb-4 text-center">Select Your School</h2>

                <select
                    value={data.school_id}
                    onChange={(e) => setData('school_id', e.target.value)}
                    className="w-full p-2 border rounded mb-4"
                    required
                >
                    <option value="">-- Choose a school --</option>
                    {schools.map((school) => (
                        <option key={school.id} value={school.id}>
                            {school.name}
                        </option>
                    ))}
                </select>

                {errors.school_id && (
                    <p className="text-red-500 text-sm mb-2">{errors.school_id}</p>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700"
                >
                    Continue
                </button>
            </form>
        </div>
    );
}