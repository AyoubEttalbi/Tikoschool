import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import React from 'react';
import Content from '@/Layouts/content';

export default function Dashboard() {
    return (
        
        <AuthenticatedLayout
      
            
          
        >
           <Content/>
       
         
        </AuthenticatedLayout>
  
    );
}
