import React, { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';

const AbsenceListPage = ({ teachers = [], classes = [] }) => {
    const [selectedTeacher, setSelectedTeacher] = useState('');
    const [selectedClass, setSelectedClass] = useState('');
    const [selectedMonth, setSelectedMonth] = useState(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    });
    const [availableClasses, setAvailableClasses] = useState([]);
    const [downloading, setDownloading] = useState(false);
    const role = usePage().props.auth.user.role;

    // Fetch teachers and classes if not provided (for SSR, you can fetch via props)
    useEffect(() => {
        if (selectedTeacher) {
            // Optionally, fetch classes for the selected teacher
            // For now, filter from all classes
            setAvailableClasses(classes.filter(c => c.teachers?.some(t => t.id == selectedTeacher)));
        } else {
            setAvailableClasses([]);
        }
    }, [selectedTeacher, classes]);

    const handleDownload = (e) => {
        e.preventDefault();
        setDownloading(true);
        const url = `/absence-list/download?teacher_id=${selectedTeacher}&class_id=${selectedClass}&date=${selectedMonth}-01`;
        window.open(url, '_blank');
        setTimeout(() => setDownloading(false), 1000);
    };

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 max-w-2xl mx-auto">
            <h1 className="text-lg font-semibold mb-6">Télécharger la liste de présence</h1>
            <form onSubmit={handleDownload} className="space-y-6">
                <div>
                    <label className="block text-sm font-medium mb-1">Enseignant</label>
                    <select
                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                        value={selectedTeacher}
                        onChange={e => setSelectedTeacher(e.target.value)}
                        required
                    >
                        <option value="">Sélectionner un enseignant</option>
                        {teachers.map(t => (
                            <option key={t.id} value={t.id}>
                                {t.first_name} {t.last_name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Classe</label>
                    <select
                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                        value={selectedClass}
                        onChange={e => setSelectedClass(e.target.value)}
                        required
                        disabled={!selectedTeacher}
                    >
                        <option value="">Sélectionner une classe</option>
                        {availableClasses.map(c => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Mois</label>
                    <input
                        type="month"
                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaPurple focus:ring-lamaPurple"
                        value={selectedMonth}
                        onChange={e => setSelectedMonth(e.target.value)}
                        required
                    />
                </div>
                <button
                    type="submit"
                    className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-lamaPurple text-white rounded-md font-semibold hover:bg-lamaPurpleDark transition"
                    disabled={downloading || !selectedTeacher || !selectedClass || !selectedMonth}
                >
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V4" /></svg>
                    {downloading ? 'Téléchargement...' : 'Télécharger la liste'}
                </button>
            </form>
        </div>
    );
};

AbsenceListPage.layout = page => <DashboardLayout>{page}</DashboardLayout>;
export default AbsenceListPage; 