import LevelsList from '@/Components/LevelCard';
import SubjectsList from '@/Components/SubjectCard';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { usePage } from '@inertiajs/react';
import React from 'react'

export default function Othersettings({levels , subjects}) {
  console.log("levelssssss" ,levels);
  const role = usePage().props.auth.user.role;
  return (
    <div>
      <SubjectsList subjectsData={subjects}/>
      <LevelsList levelsData={levels}/>
    </div>
  )
}
Othersettings.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;